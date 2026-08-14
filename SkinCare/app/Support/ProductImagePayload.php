<?php

namespace App\Support;

use App\Models\ProductImage;
use Illuminate\Support\Facades\Storage;

final class ProductImagePayload
{
    public static function make(ProductImage $image): array
    {
        $disk = Storage::disk($image->disk);

        return [
            'id' => $image->id,
            'alt_text' => $image->alt_text,
            'width' => $image->width,
            'height' => $image->height,
            'sort_order' => $image->sort_order,
            'is_primary' => $image->is_primary,
            'sources' => [
                'webp' => [
                    'url' => $disk->url($image->path),
                    'type' => 'image/webp',
                ],
                'avif' => $image->avif_path ? [
                    'url' => $disk->url($image->avif_path),
                    'type' => 'image/avif',
                ] : null,
            ],
        ];
    }
}
