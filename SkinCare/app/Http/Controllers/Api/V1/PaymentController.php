<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PaymentAttempt;
use App\Services\Payments\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments) {}

    public function show(Request $request, string $orderNumber): JsonResponse
    {
        $order = $this->ownedOrder($request, $orderNumber);
        $payment = Payment::query()->where('order_id', $order->getKey())->with('attempts')->first();

        return response()->json(['data' => $payment ? $this->paymentPayload($payment) : null]);
    }

    public function store(Request $request, string $orderNumber): JsonResponse
    {
        $request->validate([
            'amount_irr' => ['prohibited'],
            'currency' => ['prohibited'],
            'provider' => ['prohibited'],
            'authority' => ['prohibited'],
        ]);

        $idempotencyKey = trim((string) $request->header('Idempotency-Key'));
        if (! preg_match('/^[A-Za-z0-9._:-]{16,100}$/', $idempotencyKey)) {
            throw ValidationException::withMessages([
                'idempotency_key' => ['هدر Idempotency-Key معتبر و الزامی است.'],
            ]);
        }

        $order = $this->ownedOrder($request, $orderNumber);
        [$payment, $attempt, $created] = $this->payments->initiate($request->user(), $order, $idempotencyKey);

        return response()->json([
            'data' => [
                'payment' => $this->paymentPayload($payment),
                'attempt' => $this->attemptPayload($attempt),
            ],
        ], $created ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    private function ownedOrder(Request $request, string $orderNumber): Order
    {
        return Order::query()
            ->where('user_id', $request->user()->getKey())
            ->where('order_number', $orderNumber)
            ->firstOrFail();
    }

    private function paymentPayload(Payment $payment): array
    {
        return [
            'status' => $payment->status->value,
            'amount_irr' => $payment->amount_irr,
            'currency' => $payment->currency,
            'provider' => $payment->provider,
            'paid_at' => $payment->paid_at?->toISOString(),
            'refunded_at' => $payment->refunded_at?->toISOString(),
        ];
    }

    private function attemptPayload(PaymentAttempt $attempt): array
    {
        return [
            'attempt_id' => $attempt->public_id,
            'status' => $attempt->status->value,
            'amount_irr' => $attempt->amount_irr,
            'provider' => $attempt->provider,
            'redirect_url' => $attempt->redirect_url,
            'requested_at' => $attempt->requested_at?->toISOString(),
            'verified_at' => $attempt->verified_at?->toISOString(),
            'failed_at' => $attempt->failed_at?->toISOString(),
        ];
    }
}
