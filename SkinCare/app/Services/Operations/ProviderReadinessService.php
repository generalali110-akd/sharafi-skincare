<?php

namespace App\Services\Operations;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

final class ProviderReadinessService
{
    private const SMSIR_BASE_URL = 'https://api.sms.ir';

    public function inspect(bool $probeSmsIr = false): array
    {
        $checks = [
            $this->check('app.https', $this->isHttpsUrl((string) config('app.url')), 'APP_URL must use HTTPS.'),
            $this->check('session.secure_cookie', (bool) config('session.secure'), 'SESSION_SECURE_COOKIE must be enabled.'),
            $this->check('session.encrypted', (bool) config('session.encrypt'), 'SESSION_ENCRYPT must be enabled.'),
            $this->check('sms.driver', config('sms.driver') === 'smsir', 'SMS_DRIVER must be smsir.'),
            $this->checkSmsIrApiKey(),
            $this->checkSmsIrOtpTemplate(),
            $this->checkSmsIrLineNumber(),
            $this->check('payment.driver', config('payment.driver') === 'zarinpal', 'PAYMENT_DRIVER must be zarinpal.'),
            $this->checkZarinpalMerchantId(),
            $this->check('payment.callback_https', $this->isHttpsUrl((string) config('payment.callback_url')), 'PAYMENT_CALLBACK_URL must use HTTPS.'),
            $this->check('payment.result_https', $this->isHttpsUrl((string) config('payment.result_url')), 'PAYMENT_RESULT_URL must use HTTPS.'),
            $this->checkSameHost('payment.callback_host', (string) config('payment.callback_url'), (string) config('app.url'), 'PAYMENT_CALLBACK_URL must use the configured APP_URL host.'),
            $this->checkSameHost('payment.result_host', (string) config('payment.result_url'), (string) config('app.url'), 'PAYMENT_RESULT_URL must use the configured APP_URL host.'),
            $this->checkHostList('sanctum.stateful_domains', config('sanctum.stateful', []), (string) config('app.url'), 'SANCTUM_STATEFUL_DOMAINS must include the configured APP_URL host.'),
            $this->checkOriginList('cors.allowed_origins', config('cors.allowed_origins', []), (string) config('app.url'), 'CORS_ALLOWED_ORIGINS must include the configured APP_URL origin.'),
            $this->checkZarinpalBaseUrl(),
        ];

        if ($probeSmsIr) {
            $checks = [...$checks, ...$this->probeSmsIr()];
        }

        return [
            'ok' => collect($checks)->every(static fn (array $check): bool => $check['ok']),
            'checks' => $checks,
        ];
    }

    private function probeSmsIr(): array
    {
        if (config('sms.driver') !== 'smsir' || ! $this->validSmsIrApiKey()) {
            return [
                $this->check('smsir.auth_probe', false, 'SMS.ir probe skipped because the driver or API key is not ready.'),
                $this->check('smsir.line_probe', false, 'SMS.ir line probe skipped because the driver or API key is not ready.'),
            ];
        }

        try {
            $client = Http::acceptJson()
                ->withHeaders(['X-API-KEY' => $this->smsIrApiKey()])
                ->connectTimeout(max(1, min(10, (int) config('sms.smsir.connect_timeout_seconds', 3))))
                ->timeout(max(2, min(30, (int) config('sms.smsir.timeout_seconds', 8))));

            $credit = $client->get(self::SMSIR_BASE_URL.'/v1/credit');
            $creditPayload = $credit->json();
            $creditOk = $credit->successful()
                && is_array($creditPayload)
                && (int) ($creditPayload['status'] ?? 0) === 1
                && is_numeric($creditPayload['data'] ?? null);

            $lines = $client->get(self::SMSIR_BASE_URL.'/v1/line');
            $linesPayload = $lines->json();
            $configuredLine = trim((string) config('sms.smsir.line_number', ''));
            $providerLines = is_array($linesPayload) && is_array($linesPayload['data'] ?? null)
                ? array_map(static fn ($line): string => (string) $line, $linesPayload['data'])
                : [];
            $lineOk = $lines->successful()
                && is_array($linesPayload)
                && (int) ($linesPayload['status'] ?? 0) === 1
                && $configuredLine !== ''
                && in_array($configuredLine, $providerLines, true);

            return [
                $this->check('smsir.auth_probe', $creditOk, 'SMS.ir credential/connectivity probe failed.'),
                $this->check('smsir.line_probe', $lineOk, 'Configured SMS.ir line is not available to the account.'),
            ];
        } catch (ConnectionException) {
            return [
                $this->check('smsir.auth_probe', false, 'SMS.ir is unreachable from this runtime.'),
                $this->check('smsir.line_probe', false, 'SMS.ir line probe could not be completed.'),
            ];
        }
    }

    private function checkSmsIrApiKey(): array
    {
        return $this->check('smsir.api_key', $this->validSmsIrApiKey(), 'SMSIR_API_KEY is missing or unsafe.');
    }

    private function checkSmsIrOtpTemplate(): array
    {
        $sandbox = (bool) config('sms.smsir.sandbox');
        $template = config('sms.smsir.otp_template_id');
        $ok = $sandbox && ($template === null || $template === '')
            ? true
            : is_numeric($template) && (int) $template > 0;

        return $this->check('smsir.otp_template', $ok, 'SMSIR_OTP_TEMPLATE_ID is required outside the SMS.ir sandbox.');
    }

    private function checkSmsIrLineNumber(): array
    {
        return $this->check(
            'smsir.line_number',
            preg_match('/^\d{5,18}$/', trim((string) config('sms.smsir.line_number', ''))) === 1,
            'SMSIR_LINE_NUMBER must be configured for transactional order notifications.',
        );
    }

    private function checkZarinpalMerchantId(): array
    {
        $merchantId = trim((string) config('payment.zarinpal.merchant_id', ''));
        $ok = preg_match('/^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$/', $merchantId) === 1;

        return $this->check('zarinpal.merchant_id', $ok, 'ZARINPAL_MERCHANT_ID is missing or invalid.');
    }

    private function checkZarinpalBaseUrl(): array
    {
        $sandbox = (bool) config('payment.zarinpal.sandbox');
        $url = $sandbox
            ? (string) config('payment.zarinpal.sandbox_base_url')
            : (string) config('payment.zarinpal.base_url');

        return $this->check('zarinpal.base_https', $this->isHttpsUrl($url), 'The active Zarinpal base URL must use HTTPS.');
    }

    private function checkSameHost(string $name, string $url, string $appUrl, string $failure): array
    {
        $urlHost = $this->host($url);
        $appHost = $this->host($appUrl);

        return $this->check($name, $urlHost !== '' && $urlHost === $appHost, $failure);
    }

    private function checkHostList(string $name, mixed $hostList, string $appUrl, string $failure): array
    {
        $appHost = $this->host($appUrl);
        $configuredHosts = is_array($hostList) ? $hostList : explode(',', (string) $hostList);
        $hosts = array_filter(array_map(static function (string $host): string {
            return strtolower(trim(explode(':', $host, 2)[0]));
        }, $configuredHosts));

        return $this->check($name, $appHost !== '' && in_array($appHost, $hosts, true), $failure);
    }

    private function checkOriginList(string $name, mixed $origins, string $appUrl, string $failure): array
    {
        $appOrigin = $this->origin($appUrl);
        $configured = collect(is_array($origins) ? $origins : [])
            ->map(fn (string $origin): string => $this->origin($origin))
            ->filter()
            ->all();

        return $this->check($name, $appOrigin !== '' && in_array($appOrigin, $configured, true), $failure);
    }

    private function validSmsIrApiKey(): bool
    {
        $apiKey = $this->smsIrApiKey();

        return $apiKey !== ''
            && mb_strlen($apiKey) <= 512
            && ! str_contains($apiKey, "\r")
            && ! str_contains($apiKey, "\n");
    }

    private function smsIrApiKey(): string
    {
        return trim((string) config('sms.smsir.api_key', ''));
    }

    private function isHttpsUrl(string $url): bool
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        return strtolower((string) parse_url($url, PHP_URL_SCHEME)) === 'https';
    }

    private function host(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        return strtolower((string) parse_url($url, PHP_URL_HOST));
    }

    private function origin(string $url): string
    {
        if (! filter_var($url, FILTER_VALIDATE_URL)) {
            return '';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $port = parse_url($url, PHP_URL_PORT);

        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme.'://'.$host.($port ? ':'.$port : '');
    }

    private function check(string $name, bool $ok, string $failure): array
    {
        return [
            'name' => $name,
            'ok' => $ok,
            'message' => $ok ? 'ok' : $failure,
        ];
    }
}
