<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

final class ProductImageService
{
    private const PUBLIC_DISK = 'public';

    public function store(Product $product, UploadedFile $image, array $data): ProductImage
    {
        $path = $image->store("products/{$product->getKey()}", self::PUBLIC_DISK);

        try {
            return DB::transaction(function () use ($product, $path, $data): ProductImage {
                $lockedProduct = Product::query()
                    ->whereKey($product->getKey())
                    ->lockForUpdate()
                    ->firstOrFail();

                $shouldBePrimary = (bool) ($data['is_primary'] ?? ! $lockedProduct->images()->exists());

                if ($shouldBePrimary) {
                    $lockedProduct->images()->update(['is_primary' => false]);
                }

                $nextSortOrder = ((int) $lockedProduct->images()->max('sort_order')) + 1;

                return $lockedProduct->images()->create([
                    'variant_id' => $data['variant_id'] ?? null,
                    'disk' => self::PUBLIC_DISK,
                    'path' => $path,
                    'alt_text' => $data['alt_text'] ?? $lockedProduct->name,
                    'sort_order' => $nextSortOrder,
                    'is_primary' => $shouldBePrimary,
                ]);
            }, 3);
        } catch (\Throwable $exception) {
            Storage::disk(self::PUBLIC_DISK)->delete($path);

            throw $exception;
        }
    }

    public function update(Product $product, ProductImage $image, array $data): ProductImage
    {
        return DB::transaction(function () use ($product, $image, $data): ProductImage {
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedImage = $lockedProduct->images()
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            if (array_key_exists('is_primary', $data) && (bool) $data['is_primary']) {
                $lockedProduct->images()
                    ->whereKeyNot($lockedImage->getKey())
                    ->update(['is_primary' => false]);
            }

            $lockedImage->fill([
                'alt_text' => array_key_exists('alt_text', $data) ? $data['alt_text'] : $lockedImage->alt_text,
                'is_primary' => array_key_exists('is_primary', $data) ? (bool) $data['is_primary'] : $lockedImage->is_primary,
            ]);
            $lockedImage->save();

            if (! $lockedImage->is_primary && ! $lockedProduct->images()->where('is_primary', true)->exists()) {
                $replacement = $lockedProduct->images()
                    ->whereKeyNot($lockedImage->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first();

                if ($replacement) {
                    $replacement->update(['is_primary' => true]);
                } else {
                    $lockedImage->update(['is_primary' => true]);
                }
            }

            return $lockedImage->fresh();
        }, 3);
    }

    public function destroy(Product $product, ProductImage $image): void
    {
        [$disk, $path] = DB::transaction(function () use ($product, $image): array {
            $lockedProduct = Product::query()
                ->whereKey($product->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $lockedImage = $lockedProduct->images()
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $disk = $lockedImage->disk;
            $path = $lockedImage->path;
            $wasPrimary = $lockedImage->is_primary;
            $lockedImage->delete();

            if ($wasPrimary) {
                $lockedProduct->images()
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->first()
                    ?->update(['is_primary' => true]);
            }

            return [$disk, $path];
        }, 3);

        if ($disk !== self::PUBLIC_DISK) {
            Log::warning('product_image_delete_skipped_unexpected_disk', [
                'product_id' => $product->getKey(),
                'image_id' => $image->getKey(),
                'disk' => $disk,
            ]);

            return;
        }

        if (! Storage::disk(self::PUBLIC_DISK)->delete($path)) {
            Log::warning('product_image_file_delete_failed', [
                'product_id' => $product->getKey(),
                'image_id' => $image->getKey(),
                'path' => $path,
            ]);
        }
    }
}
