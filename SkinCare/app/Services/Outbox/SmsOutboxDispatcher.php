<?php

namespace App\Services\Outbox;

use App\Contracts\SmsGateway;
use App\Exceptions\PermanentSmsDeliveryException;
use App\Models\Order;
use App\Models\OutboxMessage;
use App\Services\Notifications\OrderSmsComposer;
use App\Support\IranMobile;
use Illuminate\Support\Facades\DB;
use Throwable;

final class SmsOutboxDispatcher
{
    public const RESULT_EMPTY = 'empty';

    public const RESULT_PROCESSED = 'processed';

    public const RESULT_FAILED = 'failed';

    public function __construct(
        private readonly SmsGateway $sms,
        private readonly OrderSmsComposer $composer,
    ) {}

    public function dispatchOne(): string
    {
        $message = $this->claim();
        if (! $message) {
            return self::RESULT_EMPTY;
        }

        if ($message->expires_at?->isPast()) {
            $this->failPermanently($message, 'expired');

            return self::RESULT_FAILED;
        }

        try {
            if ($message->topic !== 'sms' || $message->aggregate_type !== 'order') {
                throw new \UnexpectedValueException('Unsupported outbox message.');
            }

            $order = Order::query()
                ->with('user:id,mobile')
                ->findOrFail((int) $message->aggregate_id);
            $mobile = IranMobile::normalize((string) $order->user?->mobile);

            if (! IranMobile::isValid($mobile)) {
                throw new \UnexpectedValueException('Order user mobile is invalid.');
            }

            $this->sms->sendMessage(
                $mobile,
                $this->composer->compose($message, $order),
                $message->event_key,
            );

            OutboxMessage::query()->whereKey($message->getKey())->update([
                'processed_at' => now(),
                'locked_at' => null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

            return self::RESULT_PROCESSED;
        } catch (Throwable $exception) {
            $safeError = class_basename($exception);

            if ($exception instanceof PermanentSmsDeliveryException) {
                $this->failPermanently($message, $safeError);
            } else {
                $this->releaseOrFail($message, $safeError);
            }

            return self::RESULT_FAILED;
        }
    }

    private function claim(): ?OutboxMessage
    {
        $lockTtlSeconds = max(30, min(3600, (int) config('sms.outbox.lock_ttl_seconds', 300)));
        $staleBefore = now()->subSeconds($lockTtlSeconds);

        return DB::transaction(function () use ($staleBefore): ?OutboxMessage {
            $message = OutboxMessage::query()
                ->where('topic', 'sms')
                ->whereNull('processed_at')
                ->whereNull('failed_at')
                ->where('available_at', '<=', now())
                ->where(function ($query) use ($staleBefore): void {
                    $query->whereNull('locked_at')->orWhere('locked_at', '<=', $staleBefore);
                })
                ->orderBy('id')
                ->lock('for update skip locked')
                ->first();

            if (! $message) {
                return null;
            }

            $message->locked_at = now();
            $message->attempts++;
            $message->save();

            return $message;
        }, attempts: 3);
    }

    private function releaseOrFail(OutboxMessage $message, string $safeError): void
    {
        $maxAttempts = max(1, min(20, (int) config('sms.outbox.max_attempts', 8)));

        if ($message->attempts >= $maxAttempts) {
            $this->failPermanently($message, $safeError);

            return;
        }

        $delaySeconds = min(3600, 30 * (2 ** min(6, max(0, $message->attempts - 1))));

        OutboxMessage::query()->whereKey($message->getKey())->update([
            'available_at' => now()->addSeconds($delaySeconds),
            'locked_at' => null,
            'last_error' => mb_substr($safeError, 0, 190),
            'updated_at' => now(),
        ]);
    }

    private function failPermanently(OutboxMessage $message, string $safeError): void
    {
        OutboxMessage::query()->whereKey($message->getKey())->update([
            'failed_at' => now(),
            'locked_at' => null,
            'last_error' => mb_substr($safeError, 0, 190),
            'updated_at' => now(),
        ]);
    }
}
