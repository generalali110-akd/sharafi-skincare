<?php

namespace App\Services\Payments;

use App\Contracts\PaymentGateway;
use App\Enums\OrderStatus;
use App\Enums\PaymentAttemptStatus;
use App\Enums\PaymentStatus;
use App\Exceptions\CheckoutConflictException;
use App\Exceptions\PaymentAttemptRetryRequiredException;
use App\Exceptions\PaymentInitiationUnknownException;
use App\Exceptions\PaymentUnavailableException;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class PaymentService
{
    public function __construct(private readonly PaymentGateway $gateway) {}

    public function initiate(User $user, Order $order, string $idempotencyKey): array
    {
        if ($order->user_id !== $user->getKey()) {
            abort(404);
        }
        if ($this->gateway->name() === 'null') {
            throw new PaymentUnavailableException('درگاه پرداخت هنوز پیکربندی نشده است.');
        }

        [$payment, $attempt, $created] = DB::transaction(function () use ($order, $idempotencyKey): array {
            $lockedOrder = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();
            if ($lockedOrder->status !== OrderStatus::PendingPayment) {
                throw new CheckoutConflictException('این سفارش در وضعیت قابل پرداخت نیست.');
            }
            if (! $lockedOrder->reservation_expires_at || $lockedOrder->reservation_expires_at->lte(now())) {
                throw new CheckoutConflictException('مهلت پرداخت این سفارش به پایان رسیده است.');
            }

            $payment = Payment::query()->where('order_id', $lockedOrder->getKey())->lockForUpdate()->first();
            if (! $payment) {
                $payment = Payment::query()->create([
                    'order_id' => $lockedOrder->getKey(),
                    'amount_irr' => $lockedOrder->total_irr,
                    'currency' => 'IRR',
                    'provider' => $this->gateway->name(),
                    'status' => PaymentStatus::Pending,
                ]);
                $payment = Payment::query()->whereKey($payment->getKey())->lockForUpdate()->firstOrFail();
            }

            if ($payment->amount_irr !== $lockedOrder->total_irr || $payment->currency !== 'IRR') {
                throw new CheckoutConflictException('مبلغ پرداخت با سفارش سازگار نیست.');
            }
            if ($payment->provider !== $this->gateway->name()) {
                throw new CheckoutConflictException('درگاه این پرداخت با تلاش قبلی سازگار نیست.');
            }
            if ($payment->status === PaymentStatus::Paid) {
                throw new CheckoutConflictException('این سفارش قبلاً پرداخت شده است.');
            }

            $keyHash = hash('sha256', $idempotencyKey);
            $existing = PaymentAttempt::query()
                ->where('payment_id', $payment->getKey())
                ->where('idempotency_key_hash', $keyHash)
                ->first();
            if ($existing) {
                return [$payment, $existing, false];
            }

            if ($payment->status === PaymentStatus::Failed) {
                $payment->status = PaymentStatus::Pending;
                $payment->save();
            }

            $attemptNumber = ((int) PaymentAttempt::query()
                ->where('payment_id', $payment->getKey())
                ->max('attempt_number')) + 1;

            $attempt = PaymentAttempt::query()->create([
                'payment_id' => $payment->getKey(),
                'attempt_number' => $attemptNumber,
                'public_id' => (string) Str::ulid(),
                'idempotency_key_hash' => $keyHash,
                'provider' => $this->gateway->name(),
                'status' => PaymentAttemptStatus::Created,
                'amount_irr' => $lockedOrder->total_irr,
            ]);

            return [$payment, $attempt, true];
        }, attempts: 3);

        if (! $created) {
            if (in_array($attempt->status, [PaymentAttemptStatus::Created, PaymentAttemptStatus::Failed], true)) {
                throw new PaymentAttemptRetryRequiredException('وضعیت شروع پرداخت قبلی نامشخص است؛ برای تلاش جدید شناسه جدید ایجاد کنید.');
            }

            return [$payment, $attempt, false];
        }

        if ($attempt->status !== PaymentAttemptStatus::Created) {
            throw new PaymentUnavailableException('وضعیت تلاش پرداخت برای شروع معتبر نیست.');
        }

        try {
            $result = $this->gateway->initiate($attempt, (string) config('payment.callback_url'));
            $this->assertValidInitiation($result->authority, $result->redirectUrl);
        } catch (Throwable $exception) {
            if (! $exception instanceof PaymentInitiationUnknownException) {
                DB::transaction(function () use ($attempt, $exception): void {
                    $locked = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
                    if ($locked->status === PaymentAttemptStatus::Created) {
                        $locked->status = PaymentAttemptStatus::Failed;
                        $locked->failure_code = 'initiation_failed';
                        $locked->failure_message = $exception instanceof PaymentUnavailableException
                            ? mb_substr($exception->getMessage(), 0, 500)
                            : 'Payment gateway initiation failed.';
                        $locked->failed_at = now();
                        $locked->save();
                    }
                });
            }

            throw $exception;
        }

        $attempt = DB::transaction(function () use ($attempt, $result): PaymentAttempt {
            $locked = PaymentAttempt::query()->whereKey($attempt->getKey())->lockForUpdate()->firstOrFail();
            if ($locked->status === PaymentAttemptStatus::Created) {
                $locked->status = PaymentAttemptStatus::Pending;
                $locked->authority = $result->authority;
                $locked->redirect_url = $result->redirectUrl;
                $locked->requested_at = now();
                $locked->metadata = $result->metadata;
                $locked->save();
            }

            return $locked;
        });

        return [$payment->refresh(), $attempt, true];
    }

    private function assertValidInitiation(string $authority, string $redirectUrl): void
    {
        $scheme = strtolower((string) parse_url($redirectUrl, PHP_URL_SCHEME));
        if ($authority === '' || ! filter_var($redirectUrl, FILTER_VALIDATE_URL) || ! in_array($scheme, ['http', 'https'], true)) {
            throw new PaymentUnavailableException('پاسخ درگاه پرداخت معتبر نیست.');
        }
        if (app()->environment('production') && $scheme !== 'https') {
            throw new PaymentUnavailableException('آدرس هدایت درگاه پرداخت امن نیست.');
        }
    }
}
