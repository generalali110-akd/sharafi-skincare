<?php

namespace App\Infrastructure\Testing;

use App\Contracts\PaymentGateway;
use App\Exceptions\PaymentInitiationUnknownException;
use App\Exceptions\PaymentUnavailableException;
use App\Models\PaymentAttempt;
use App\ValueObjects\Payments\PaymentInitiationResult;
use App\ValueObjects\Payments\PaymentVerificationResult;
use RuntimeException;

final class E2ePaymentGateway implements PaymentGateway
{
    public function name(): string
    {
        return 'e2e';
    }

    public function initiate(PaymentAttempt $attempt, string $callbackUrl): PaymentInitiationResult
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2E payment initiation is restricted to the testing environment.');
        }

        $mode = $this->consumeMode();
        if ($mode === 'unavailable_once') {
            throw new PaymentUnavailableException('E2E payment gateway is temporarily unavailable.');
        }
        if ($mode === 'unknown_once') {
            throw new PaymentInitiationUnknownException('E2E payment initiation result is unknown.');
        }

        $order = $attempt->payment()->with('order')->firstOrFail()->order;
        $resultUrl = trim((string) config('payment.result_url'));
        if ($resultUrl === '') {
            $resultUrl = rtrim((string) config('app.url'), '/').'/payment-result.html';
        }

        $separator = str_contains($resultUrl, '?') ? '&' : '?';

        return new PaymentInitiationResult(
            authority: 'E2E-'.$attempt->public_id,
            redirectUrl: $resultUrl.$separator.'order='.rawurlencode($order->order_number),
            metadata: ['environment' => 'testing'],
        );
    }

    public function verify(PaymentAttempt $attempt, array $payload): PaymentVerificationResult
    {
        if (! app()->environment('testing')) {
            throw new RuntimeException('E2E payment verification is restricted to the testing environment.');
        }

        return new PaymentVerificationResult(
            successful: true,
            transactionId: 'E2E-TX-'.$attempt->public_id,
            eventId: hash('sha256', 'e2e-event:'.$attempt->public_id),
            metadata: ['environment' => 'testing'],
        );
    }

    private function consumeMode(): string
    {
        $path = storage_path('framework/e2e/payment-mode.json');
        if (! is_file($path)) {
            return 'success';
        }

        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new RuntimeException('Unable to open E2E payment mode state.');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('Unable to lock E2E payment mode state.');
            }

            rewind($handle);
            $payload = json_decode((string) stream_get_contents($handle), true);
            $mode = is_array($payload) ? (string) ($payload['mode'] ?? 'success') : 'success';

            if (in_array($mode, ['unavailable_once', 'unknown_once'], true)) {
                rewind($handle);
                ftruncate($handle, 0);
                fwrite($handle, (string) json_encode(['mode' => 'success'], JSON_UNESCAPED_SLASHES));
                fflush($handle);
            }

            return in_array($mode, ['success', 'unavailable_once', 'unknown_once'], true) ? $mode : 'success';
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
