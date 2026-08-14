<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\File;

final class StoreProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $maxKilobytes = max(256, (int) ceil(((int) config('media.product_images.max_bytes', 8 * 1024 * 1024)) / 1024));

        return [
            'image' => [
                'required',
                File::types(['jpg', 'jpeg', 'png', 'webp', 'avif'])->max($maxKilobytes),
            ],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'alt_text' => ['nullable', 'string', 'max:220'],
            'sort_order' => ['sometimes', 'integer', 'between:-10000,10000'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
