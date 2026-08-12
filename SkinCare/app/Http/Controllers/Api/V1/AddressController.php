<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Address;
use App\Services\Commerce\AddressService;
use App\Support\IranMobile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;

class AddressController extends Controller
{
    public function __construct(private readonly AddressService $addresses) {}

    public function index(Request $request): JsonResponse
    {
        $addresses = Address::query()
            ->where('user_id', $request->user()->getKey())
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->get()
            ->map(fn (Address $address) => $this->payload($address));

        return response()->json(['data' => $addresses]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validated($request, false);
        $address = $this->addresses->create($request->user(), $data);

        return response()->json(['data' => $this->payload($address)], Response::HTTP_CREATED);
    }

    public function update(Request $request, int $address): JsonResponse
    {
        $data = $this->validated($request, true);
        $address = $this->addresses->update($request->user(), $address, $data);

        return response()->json(['data' => $this->payload($address)]);
    }

    public function destroy(Request $request, int $address): Response
    {
        $this->addresses->delete($request->user(), $address);

        return response()->noContent();
    }

    private function validated(Request $request, bool $partial): array
    {
        $presence = $partial ? 'sometimes' : 'required';
        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:80'],
            'recipient_name' => [$presence, 'string', 'max:120'],
            'mobile' => [$presence, 'string', 'max:30'],
            'province' => [$presence, 'string', 'max:100'],
            'city' => [$presence, 'string', 'max:100'],
            'postal_code' => [$presence, 'string', 'regex:/^\d{10}$/'],
            'address_line' => [$presence, 'string', 'max:1000'],
            'is_default' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('mobile', $data)) {
            $data['mobile'] = IranMobile::normalize($data['mobile']);
            if (! IranMobile::isValid($data['mobile'])) {
                throw ValidationException::withMessages([
                    'mobile' => ['شماره موبایل معتبر نیست.'],
                ]);
            }
        }

        return $data;
    }

    private function payload(Address $address): array
    {
        return [
            'id' => $address->id,
            'title' => $address->title,
            'recipient_name' => $address->recipient_name,
            'mobile' => $address->mobile,
            'province' => $address->province,
            'city' => $address->city,
            'postal_code' => $address->postal_code,
            'address_line' => $address->address_line,
            'is_default' => $address->is_default,
        ];
    }
}
