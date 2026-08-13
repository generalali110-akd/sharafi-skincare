<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Payments\ZarinpalCallbackService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ZarinpalCallbackController extends Controller
{
    public function __construct(private readonly ZarinpalCallbackService $callbacks) {}

    public function __invoke(Request $request): JsonResponse|RedirectResponse
    {
        $result = $this->callbacks->handle($request->query());
        $resultUrl = trim((string) config('payment.result_url'));

        if ($this->validResultUrl($resultUrl)) {
            $query = http_build_query(array_filter([
                'payment_status' => $result['status'] ?? 'failed',
                'order' => $result['order_number'] ?? null,
                'code' => $result['failure_code'] ?? null,
            ], static fn ($value): bool => $value !== null && $value !== ''));

            return redirect()->away($resultUrl.(str_contains($resultUrl, '?') ? '&' : '?').$query, 303);
        }

        return response()->json(['data' => $result]);
    }

    private function validResultUrl(string $url): bool
    {
        if ($url === '' || ! filter_var($url, FILTER_VALIDATE_URL)) {
            return false;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, app()->environment('production') ? ['https'] : ['http', 'https'], true);
    }
}
