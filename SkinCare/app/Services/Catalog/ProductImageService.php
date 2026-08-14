<?php

namespace App\Services\Catalog;

use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

final class ProductImageService
{
    private const SUPPORTED_MIME = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/avif',
    ];

    public function __construct(private readonly AuditLogger $audit) {}

    public function store(
        Product $product,
        UploadedFile $upload,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProductImage {
        $this->assertRuntime();
        $this->assertVariantBelongsToProduct($product, $data['variant_id'] ?? null);

        [$image, $sourceMime, $width, $height] = $this->decodeAndValidate($upload);
        $diskName = (string) config('media.product_images.disk', 'public');
        $directory = 'products/'.$product->getKey();
        $base = Str::uuid()->toString();
        $webpPath = $directory.'/'.$base.'.webp';
        $avifPath = $directory.'/'.$base.'.avif';
        $webpTmp = $this->temporaryPath('webp');
        $avifTmp = $this->temporaryPath('avif');

        try {
            if (! imagewebp($image, $webpTmp, $this->quality('webp'))) {
                throw new RuntimeException('WebP image encoding failed.');
            }
            if (! imageavif($image, $avifTmp, $this->quality('avif'))) {
                throw new RuntimeException('AVIF image encoding failed.');
            }
        } finally {
            imagedestroy($image);
        }

        $disk = Storage::disk($diskName);
        $stored = [];

        try {
            $this->putFile($diskName, $webpPath, $webpTmp);
            $stored[] = $webpPath;
            $this->putFile($diskName, $avifPath, $avifTmp);
            $stored[] = $avifPath;

            $record = DB::transaction(function () use (
                $product,
                $data,
                $actor,
                $ipAddress,
                $userAgent,
                $diskName,
                $webpPath,
                $avifPath,
                $sourceMime,
                $width,
                $height,
                $webpTmp,
            ): ProductImage {
                Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();

                $hasImages = ProductImage::query()->where('product_id', $product->getKey())->exists();
                $makePrimary = (bool) ($data['is_primary'] ?? false) || ! $hasImages;
                if ($makePrimary) {
                    ProductImage::query()
                        ->where('product_id', $product->getKey())
                        ->update(['is_primary' => false]);
                }

                $image = ProductImage::query()->create([
                    'product_id' => $product->getKey(),
                    'variant_id' => $data['variant_id'] ?? null,
                    'disk' => $diskName,
                    'path' => $webpPath,
                    'avif_path' => $avifPath,
                    'source_mime' => $sourceMime,
                    'width' => $width,
                    'height' => $height,
                    'bytes' => filesize($webpTmp) ?: null,
                    'alt_text' => $this->altText($product, $data['alt_text'] ?? null),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'is_primary' => $makePrimary,
                ]);

                $this->audit->record(
                    actor: $actor,
                    action: 'catalog.product_image.created',
                    subject: $image,
                    changes: [
                        'product_id' => $product->getKey(),
                        'variant_id' => $image->variant_id,
                        'width' => $width,
                        'height' => $height,
                        'source_mime' => $sourceMime,
                        'is_primary' => $makePrimary,
                    ],
                    ipAddress: $ipAddress,
                    userAgent: $userAgent,
                );

                return $image;
            });
        } catch (Throwable $exception) {
            if ($stored) {
                try {
                    $disk->delete($stored);
                } catch (Throwable $cleanupException) {
                    report($cleanupException);
                }
            }
            throw $exception;
        } finally {
            @unlink($webpTmp);
            @unlink($avifTmp);
        }

        return $record->refresh();
    }

    public function update(
        Product $product,
        ProductImage $image,
        array $data,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): ProductImage {
        $this->assertImageBelongsToProduct($product, $image);
        $this->assertVariantBelongsToProduct($product, $data['variant_id'] ?? null, array_key_exists('variant_id', $data));

        return DB::transaction(function () use ($product, $image, $data, $actor, $ipAddress, $userAgent): ProductImage {
            Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked = ProductImage::query()
                ->where('product_id', $product->getKey())
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $before = $locked->only(['variant_id', 'alt_text', 'sort_order', 'is_primary']);

            if (($data['is_primary'] ?? false) === true) {
                ProductImage::query()
                    ->where('product_id', $product->getKey())
                    ->whereKeyNot($locked->getKey())
                    ->update(['is_primary' => false]);
            }

            if (array_key_exists('alt_text', $data)) {
                $data['alt_text'] = $this->altText($product, $data['alt_text']);
            }

            $wasPrimary = $locked->is_primary;
            $locked->fill($data);
            if ($wasPrimary && array_key_exists('is_primary', $data) && $data['is_primary'] === false) {
                $replacement = ProductImage::query()
                    ->where('product_id', $product->getKey())
                    ->whereKeyNot($locked->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($replacement) {
                    $replacement->update(['is_primary' => true]);
                } else {
                    $locked->is_primary = true;
                }
            }

            $locked->save();

            $this->audit->record(
                actor: $actor,
                action: 'catalog.product_image.updated',
                subject: $locked,
                changes: ['before' => $before, 'after' => $locked->only(array_keys($before))],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            return $locked->refresh();
        });
    }

    public function delete(
        Product $product,
        ProductImage $image,
        User $actor,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): void {
        $this->assertImageBelongsToProduct($product, $image);

        [$diskName, $paths] = DB::transaction(function () use ($product, $image, $actor, $ipAddress, $userAgent): array {
            Product::query()->whereKey($product->getKey())->lockForUpdate()->firstOrFail();
            $locked = ProductImage::query()
                ->where('product_id', $product->getKey())
                ->whereKey($image->getKey())
                ->lockForUpdate()
                ->firstOrFail();

            $wasPrimary = $locked->is_primary;
            $diskName = $locked->disk;
            $paths = array_values(array_filter([$locked->path, $locked->avif_path]));

            $this->audit->record(
                actor: $actor,
                action: 'catalog.product_image.deleted',
                subject: $locked,
                changes: [
                    'product_id' => $locked->product_id,
                    'variant_id' => $locked->variant_id,
                    'is_primary' => $locked->is_primary,
                ],
                ipAddress: $ipAddress,
                userAgent: $userAgent,
            );

            $locked->delete();

            if ($wasPrimary) {
                ProductImage::query()
                    ->where('product_id', $product->getKey())
                    ->orderBy('sort_order')
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first()?->update(['is_primary' => true]);
            }

            return [$diskName, $paths];
        });

        try {
            Storage::disk($diskName)->delete($paths);
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function decodeAndValidate(UploadedFile $upload): array
    {
        $path = $upload->getRealPath();
        if ($path === false || ! is_file($path)) {
            throw ValidationException::withMessages(['image' => ['فایل تصویر معتبر نیست.']]);
        }

        $size = $upload->getSize();
        $maxBytes = max(256 * 1024, (int) config('media.product_images.max_bytes', 8 * 1024 * 1024));
        if (! is_int($size) || $size <= 0 || $size > $maxBytes) {
            throw ValidationException::withMessages(['image' => ['حجم تصویر از حد مجاز بیشتر است.']]);
        }

        $info = @getimagesize($path);
        $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
        $width = is_array($info) ? (int) ($info[0] ?? 0) : 0;
        $height = is_array($info) ? (int) ($info[1] ?? 0) : 0;

        if (! in_array($mime, self::SUPPORTED_MIME, true) || $width < 64 || $height < 64) {
            throw ValidationException::withMessages(['image' => ['ساختار یا نوع تصویر پشتیبانی نمی‌شود.']]);
        }

        $maxWidth = max(64, (int) config('media.product_images.max_width', 6000));
        $maxHeight = max(64, (int) config('media.product_images.max_height', 6000));
        $maxPixels = max(4096, (int) config('media.product_images.max_pixels', 24_000_000));
        if ($width > $maxWidth || $height > $maxHeight || ($width * $height) > $maxPixels) {
            throw ValidationException::withMessages(['image' => ['ابعاد یا تعداد پیکسل‌های تصویر از حد امن بیشتر است.']]);
        }

        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/webp' => @imagecreatefromwebp($path),
            'image/avif' => @imagecreatefromavif($path),
            default => false,
        };

        if ($image === false) {
            throw ValidationException::withMessages(['image' => ['رمزگشایی تصویر ناموفق بود.']]);
        }

        if ($mime === 'image/jpeg') {
            $image = $this->applyJpegOrientation($image, $path);
        }

        imagesavealpha($image, true);

        return [$image, $mime, imagesx($image), imagesy($image)];
    }

    private function applyJpegOrientation(mixed $image, string $path): mixed
    {
        if (! function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path, 'IFD0', true, false);
        $orientation = (int) ($exif['IFD0']['Orientation'] ?? 1);

        return match ($orientation) {
            2 => $this->flip($image, IMG_FLIP_HORIZONTAL),
            3 => $this->rotate($image, 180),
            4 => $this->flip($image, IMG_FLIP_VERTICAL),
            5 => $this->flip($this->rotate($image, -90), IMG_FLIP_HORIZONTAL),
            6 => $this->rotate($image, -90),
            7 => $this->flip($this->rotate($image, 90), IMG_FLIP_HORIZONTAL),
            8 => $this->rotate($image, 90),
            default => $image,
        };
    }

    private function rotate(mixed $image, int $degrees): mixed
    {
        $rotated = imagerotate($image, $degrees, 0);
        if ($rotated === false) {
            throw new RuntimeException('Image orientation correction failed.');
        }
        imagedestroy($image);

        return $rotated;
    }

    private function flip(mixed $image, int $mode): mixed
    {
        if (! imageflip($image, $mode)) {
            throw new RuntimeException('Image orientation correction failed.');
        }

        return $image;
    }

    private function assertRuntime(): void
    {
        foreach (['imagewebp', 'imageavif', 'imagecreatefromjpeg', 'imagecreatefrompng', 'imagecreatefromwebp', 'imagecreatefromavif'] as $function) {
            if (! function_exists($function)) {
                throw new RuntimeException("Required GD image function {$function} is unavailable.");
            }
        }
    }

    private function quality(string $format): int
    {
        $default = $format === 'avif' ? 55 : 82;

        return max(1, min(100, (int) config("media.product_images.{$format}_quality", $default)));
    }

    private function putFile(string $diskName, string $path, string $temporaryPath): void
    {
        $handle = fopen($temporaryPath, 'rb');
        if ($handle === false) {
            throw new RuntimeException('Unable to open encoded image derivative.');
        }

        try {
            Storage::disk($diskName)->put($path, $handle, ['visibility' => 'public']);
        } finally {
            fclose($handle);
        }
    }

    private function temporaryPath(string $suffix): string
    {
        $directory = storage_path('app/tmp-media');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            throw new RuntimeException('Unable to create temporary media directory.');
        }

        $path = tempnam($directory, 'img-');
        if ($path === false) {
            throw new RuntimeException('Unable to create temporary media file.');
        }

        $renamed = $path.'.'.$suffix;
        if (! rename($path, $renamed)) {
            @unlink($path);
            throw new RuntimeException('Unable to prepare temporary media file.');
        }

        return $renamed;
    }

    private function assertVariantBelongsToProduct(Product $product, mixed $variantId, bool $provided = true): void
    {
        if (! $provided || $variantId === null) {
            return;
        }

        $exists = ProductVariant::query()
            ->where('product_id', $product->getKey())
            ->whereKey((int) $variantId)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['variant_id' => ['تنوع انتخاب‌شده متعلق به این محصول نیست.']]);
        }
    }

    private function assertImageBelongsToProduct(Product $product, ProductImage $image): void
    {
        if ($image->product_id !== $product->getKey()) {
            abort(404);
        }
    }

    private function altText(Product $product, mixed $value): string
    {
        $alt = trim((string) $value);

        return $alt !== '' ? mb_substr($alt, 0, 220) : mb_substr($product->name, 0, 220);
    }
}
