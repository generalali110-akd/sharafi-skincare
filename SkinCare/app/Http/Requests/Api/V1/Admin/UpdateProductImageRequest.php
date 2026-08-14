<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class UpdateProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'variant_id' => ['sometimes', 'nullable', 'integer', 'exists:product_variants,id'],
            'alt_text' => ['sometimes', 'nullable', 'string', 'max:220'],
            'sort_order' => ['sometimes', 'integer', 'between:-10000,10000'],
            'is_primary' => ['sometimes', 'boolean'],
        ];
    }
}
