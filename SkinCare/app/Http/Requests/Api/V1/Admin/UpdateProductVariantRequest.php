<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpdateProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $variant = $this->route('variant');

        return [
            'sku' => ['sometimes', 'string', 'max:100', Rule::unique('product_variants', 'sku')->ignore($variant)],
            'title' => ['sometimes', 'nullable', 'string', 'max:160'],
            'barcode' => ['sometimes', 'nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')->ignore($variant)],
            'price_irr' => ['sometimes', 'integer', 'min:0'],
            'compare_at_price_irr' => ['sometimes', 'nullable', 'integer', 'min:0'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'between:-10000,10000'],
            'on_hand' => ['prohibited'],
            'reserved' => ['prohibited'],
            'reorder_level' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $variant = $this->route('variant');
            $price = $this->filled('price_irr') ? $this->integer('price_irr') : (int) $variant->price_irr;
            $compare = $this->has('compare_at_price_irr')
                ? ($this->input('compare_at_price_irr') === null ? null : $this->integer('compare_at_price_irr'))
                : $variant->compare_at_price_irr;

            if ($compare !== null && $compare < $price) {
                $validator->errors()->add('compare_at_price_irr', 'قیمت قبل از تخفیف نمی‌تواند کمتر از قیمت فروش باشد.');
            }
        }];
    }
}
