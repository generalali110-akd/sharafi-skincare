<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ProductStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:180'],
            'slug' => ['required', 'string', 'max:220', 'regex:/^[\p{L}\p{N}]+(?:-[\p{L}\p{N}]+)*$/u', Rule::unique('products', 'slug')],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'short_description' => ['nullable', 'string', 'max:1000'],
            'description' => ['nullable', 'string', 'max:50000'],
            'status' => ['required', Rule::enum(ProductStatus::class)],
            'is_featured' => ['sometimes', 'boolean'],
            'published_at' => ['nullable', 'date'],
            'category_ids' => ['sometimes', 'array', 'max:30'],
            'category_ids.*' => ['integer', 'distinct', 'exists:categories,id'],
            'variants' => ['required', 'array', 'min:1', 'max:50'],
            'variants.*.sku' => ['required', 'string', 'max:100', 'distinct', Rule::unique('product_variants', 'sku')],
            'variants.*.title' => ['nullable', 'string', 'max:160'],
            'variants.*.barcode' => ['nullable', 'string', 'max:100', 'distinct', Rule::unique('product_variants', 'barcode')],
            'variants.*.price_irr' => ['required', 'integer', 'min:0'],
            'variants.*.compare_at_price_irr' => ['nullable', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
            'variants.*.sort_order' => ['sometimes', 'integer', 'between:-10000,10000'],
            'variants.*.on_hand' => ['prohibited'],
            'variants.*.reserved' => ['prohibited'],
            'variants.*.reorder_level' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $variants = $this->input('variants', []);

                foreach ($variants as $index => $variant) {
                    $price = isset($variant['price_irr']) ? (int) $variant['price_irr'] : null;
                    $compare = isset($variant['compare_at_price_irr']) ? (int) $variant['compare_at_price_irr'] : null;

                    if ($price !== null && $compare !== null && $compare < $price) {
                        $validator->errors()->add(
                            "variants.{$index}.compare_at_price_irr",
                            'قیمت قبل از تخفیف نمی‌تواند کمتر از قیمت فروش باشد.',
                        );
                    }
                }

                if ($this->input('status') === ProductStatus::Active->value) {
                    $hasActiveVariant = collect($variants)
                        ->contains(fn ($variant) => (bool) ($variant['is_active'] ?? true));

                    if (! $hasActiveVariant) {
                        $validator->errors()->add('variants', 'محصول فعال باید حداقل یک تنوع فعال داشته باشد.');
                    }
                }
            },
        ];
    }
}
