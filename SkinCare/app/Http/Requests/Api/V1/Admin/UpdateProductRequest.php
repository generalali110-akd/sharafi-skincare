<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $product = $this->route('product');

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:180'],
            'slug' => [
                'sometimes',
                'string',
                'max:220',
                'regex:/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u',
                Rule::unique('products', 'slug')->ignore($product),
            ],
            'brand_id' => ['sometimes', 'nullable', 'integer', 'exists:brands,id'],
            'short_description' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'description' => ['sometimes', 'nullable', 'string', 'max:50000'],
            'status' => ['sometimes', Rule::enum(ProductStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['sometimes', 'nullable', 'date'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'variants' => ['prohibited'],
        ];
    }
}
