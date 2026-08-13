<?php

namespace App\Infrastructure\Sms;

use App\Contracts\SmsGateway;
use App\Exceptions\PermanentSmsDeliveryException;
use App\Exceptions\SmsDeliveryException;
use App\Support\IranMobile;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

final class SmsIrGateway implements SmsGateway
{
    private const BASE_URL = 'https://api.sms.ir';

    private const SANDBOX_VERIFY_TEMPLATE_ID = 123456;

    public function __construct(private readonly array $config) {}

    public function sendOtp(string $mobile, string $code, int $ttlSeconds): void
    {
        unset($ttlSeconds);

        $mobile = $this->validatedMobile($mobile);
        $this->assertOtpCode($code);

        $response = $this->post('/v1/send/verify', [
            'mobile' => $mobile,
            'templateId' => $this->otpTemplateId(),
            'parameters' => [[
                'name' => $this->otpCodeParameter(),
                'value' => $code,
            ]],
        ]);

        $payload = $this->successfulPayload($response);
        $messageId = data_get($payload, 'data.messageId');

        if (! $this->positiveIntegerLike($messageId)) {
            throw new SmsDeliveryException('SMS.ir returned an invalid Verify response.');
        }
    }

    public function sendMessage(string $mobile, string $message, string $idempotencyKey): void
    {
        unset($idempotencyKey);

        $mobile = $this->validatedMobile($mobile);
        $message = trim($message);
        $maxChars = max(70, min(500, (int) ($this->config['max_message_chars'] ?? 320)));

        if ($message === '' || mb_strlen($message) > $maxChars) {
            throw new PermanentSmsDeliveryException('SMS message is empty or exceeds the configured safe length.');
        }

        $response = $this->post('/v1/send/bulk', [
            'lineNumber' => $this->lineNumber(),
            'messageText' => $message,
            'mobiles' => [$mobile],
            'sendDateTime' => null,
        ]);

        $payload = $this->successfulPayload($response);
        $messageIds = data_get($payload, 'data.messageIds');
        $messageId = is_array($messageIds) ? ($messageIds[0] ?? null) : null;

        if ((string) $messageId === '0') {
            throw new PermanentSmsDeliveryException('SMS.ir rejected the destination for this sending line.');
        }

        if (! $this->positiveIntegerLike($messageId)) {
            throw new SmsDeliveryException('SMS.ir returned an invalid bulk-send response.');
        }
    }

    private function post(string $path, array $body): Response
    {
        $this->assertApiKeyConfigured();

        try {
            return $this->client()->post(self::BASE_URL.$path, $body);
        } catch (ConnectionException $exception) {
            throw new SmsDeliveryException('SMS.ir is temporarily unreachable.', previous: $exception);
        }
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()
            ->asJson()
            ->withHeaders(['X-API-KEY' => $this->apiKey()])
            ->connectTimeout(max(1, min(10, (int) ($this->config['connect_timeout_seconds'] ?? 3))))
            ->timeout(max(2, min(30, (int) ($this->config['timeout_seconds'] ?? 8))));
    }

    private function successfulPayload(Response $response): array
    {
        $payload = $response->json();

        if (! is_array($payload)) {
            throw new SmsDeliveryException('SMS.ir returned an invalid response.');
        }

        $providerStatus = is_numeric($payload['status'] ?? null) ? (int) $payload['status'] : null;

        if ($providerStatus === 1 && $response->successful()) {
            return $payload;
        }

        $this->throwProviderFailure($response, $providerStatus);
    }

    private function throwProviderFailure(Response $response, ?int $providerStatus): never
    {
        $permanentCodes = [
            10, 11, 12, 13, 14,
            101, 103, 104, 105, 106, 107, 108, 109, 110,
            111, 112, 113, 114, 115, 116, 117, 118, 119, 123,
        ];

        if ($response->status() === 401 || in_array($providerStatus, $permanentCodes, true)) {
            throw new PermanentSmsDeliveryException($this->safeProviderMessage($providerStatus));
        }

        if ($response->status() >= 400 && $response->status() < 500 && $response->status() !== 429 && $providerStatus === null) {
            throw new PermanentSmsDeliveryException('SMS.ir rejected the request configuration.');
        }

        throw new SmsDeliveryException($this->safeProviderMessage($providerStatus));
    }

    private function assertApiKeyConfigured(): void
    {
        $apiKey = $this->apiKey();

        if (mb_strlen($apiKey) < 20 || mb_strlen($apiKey) > 200 || preg_match('/\s/', $apiKey)) {
            throw new PermanentSmsDeliveryException('SMS.ir API key is not configured or is invalid.');
        }
    }

    private function apiKey(): string
    {
        return trim((string) ($this->config['api_key'] ?? ''));
    }

    private function otpTemplateId(): int
    {
        $configured = $this->config['otp_template_id'] ?? null;

        if (($configured === null || $configured === '') && (bool) ($this->config['sandbox'] ?? false)) {
            return self::SANDBOX_VERIFY_TEMPLATE_ID;
        }

        if (! is_numeric($configured) || (int) $configured <= 0) {
            throw new PermanentSmsDeliveryException('SMS.ir OTP template ID is not configured.');
        }

        return (int) $configured;
    }

    private function otpCodeParameter(): string
    {
        $parameter = trim((string) ($this->config['otp_code_parameter'] ?? 'CODE'));

        if (! preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $parameter)) {
            throw new PermanentSmsDeliveryException('SMS.ir OTP parameter name is invalid.');
        }

        return $parameter;
    }

    private function lineNumber(): int
    {
        $line = trim((string) ($this->config['line_number'] ?? ''));

        if (! preg_match('/^\d{5,18}$/', $line)) {
            throw new PermanentSmsDeliveryException('SMS.ir sending line is not configured or is invalid.');
        }

        return (int) $line;
    }

    private function validatedMobile(string $mobile): string
    {
        $mobile = IranMobile::normalize($mobile);

        if (! IranMobile::isValid($mobile)) {
            throw new PermanentSmsDeliveryException('SMS destination mobile is invalid.');
        }

        return $mobile;
    }

    private function assertOtpCode(string $code): void
    {
        if (! preg_match('/^\d{4,10}$/', $code)) {
            throw new PermanentSmsDeliveryException('OTP code format is invalid.');
        }
    }

    private function positiveIntegerLike(mixed $value): bool
    {
        return (is_int($value) && $value > 0)
            || (is_string($value) && ctype_digit($value) && (int) $value > 0);
    }

    private function safeProviderMessage(?int $status): string
    {
        return match ($status) {
            0 => 'SMS.ir reported a temporary service error.',
            10, 11 => 'SMS.ir API key is invalid or inactive.',
            12 => 'SMS.ir API key is restricted to different IP addresses.',
            13, 14 => 'SMS.ir account is inactive or suspended.',
            20 => 'SMS.ir request rate limit was exceeded.',
            101 => 'SMS.ir sending line is invalid.',
            102 => 'SMS.ir account credit is insufficient.',
            104 => 'SMS.ir rejected the destination mobile.',
            113 => 'SMS.ir Verify template was not found.',
            114 => 'An SMS.ir Verify parameter value is too long.',
            115 => 'SMS.ir rejected the destination because of blacklist rules.',
            116, 117 => 'SMS.ir Verify template parameters are invalid.',
            119 => 'The SMS.ir account plan does not allow this template.',
            123 => 'The SMS.ir sending line requires activation.',
            default => 'SMS.ir did not accept the SMS request.',
        };
    }
}
