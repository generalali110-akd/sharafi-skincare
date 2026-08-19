<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ProductImageService
{
    public function store(Product $product, UploadedFile $image, array $data): ProductImage
    {
        $path = $image->store("products/{$product->id}", 'public');

        try {
            return DB::transaction(function () use ($product, $path, $data): ProductImage {
                $shouldBePrimary = (bool) ($data['is_primary'] ?? ! $product->images()->exists());

                if ($shouldBePrimary) {
                    $product->images()->update(['is_primary' => false]);
                }

                return $product->images()->create([
                    'variant_id' => $data['variant_id'] ?? null,
                    'disk' => 'public',
                    'path' => $path,
                    'alt_text' => $data['alt_text'] ?? $product->name,
                    'sort_order' => (int) $product->images()->max('sort_order') + 1,
                    'is_primary' => $shouldBePrimary,
                ]);
            });
        } catch (\Throwable $exception) {
            Storage::disk('public')->delete($path);

            throw $exception;
        }
    }

    public function update(Product $product, ProductImage $image, array $data): ProductImage
    {
        return DB::transaction(function () use ($product, $image, $data): ProductImage {
            if (array_key_exists('is_primary', $data) && (bool) $data['is_primary']) {
                $product->images()->whereKeyNot($image->getKey())->update(['is_primary' => false]);
            }

            $image->fill($data);
            $image->save();

            return $image->fresh();
        });
    }

    public function destroy(Product $product, ProductImage $image): void
    {
        $disk = $image->disk;
        $path = $image->path;

        DB::transaction(function () use ($product, $image): void {
            $wasPrimary = $image->is_primary;
            $image->delete();

            if ($wasPrimary) {
                $product->images()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_primary' => true]);
            }
        });

        Storage::disk($disk)->delete($path);
    }
}
