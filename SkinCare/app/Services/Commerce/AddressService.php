<?php

namespace App\Services\Commerce;

use App\Models\Address;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AddressService
{
    public function create(User $user, array $data): Address
    {
        return DB::transaction(function () use ($user, $data): Address {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $hasAddress = Address::query()->where('user_id', $user->getKey())->exists();
            $makeDefault = (bool) ($data['is_default'] ?? false) || ! $hasAddress;

            if ($makeDefault) {
                Address::query()->where('user_id', $user->getKey())->update(['is_default' => false]);
            }

            return Address::query()->create([
                ...$data,
                'user_id' => $user->getKey(),
                'is_default' => $makeDefault,
            ]);
        });
    }

    public function update(User $user, int $addressId, array $data): Address
    {
        return DB::transaction(function () use ($user, $addressId, $data): Address {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $address = Address::query()
                ->where('user_id', $user->getKey())
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();

            $requestedDefault = array_key_exists('is_default', $data) ? (bool) $data['is_default'] : null;

            if ($requestedDefault === true) {
                Address::query()
                    ->where('user_id', $user->getKey())
                    ->whereKeyNot($address->getKey())
                    ->update(['is_default' => false]);
            }

            $wasDefault = $address->is_default;
            $address->fill($data);

            if ($requestedDefault === false && $wasDefault) {
                $replacement = Address::query()
                    ->where('user_id', $user->getKey())
                    ->whereKeyNot($address->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_default' => true]);
                } else {
                    $address->is_default = true;
                }
            }

            $address->save();

            return $address->refresh();
        });
    }

    public function delete(User $user, int $addressId): void
    {
        DB::transaction(function () use ($user, $addressId): void {
            User::query()->whereKey($user->getKey())->lockForUpdate()->firstOrFail();

            $address = Address::query()
                ->where('user_id', $user->getKey())
                ->whereKey($addressId)
                ->lockForUpdate()
                ->firstOrFail();

            $wasDefault = $address->is_default;
            $address->delete();

            if ($wasDefault) {
                $replacement = Address::query()
                    ->where('user_id', $user->getKey())
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();

                $replacement?->update(['is_default' => true]);
            }
        });
    }
}
