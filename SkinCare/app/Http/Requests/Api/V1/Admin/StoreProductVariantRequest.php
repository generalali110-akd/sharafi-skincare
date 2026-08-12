<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class StoreProductVariantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'sku' => ['required', 'string', 'max:100', Rule::unique('product_variants', 'sku')],
            'title' => ['nullable', 'string', 'max:160'],
            'barcode' => ['nullable', 'string', 'max:100', Rule::unique('product_variants', 'barcode')],
            'price_irr' => ['required', 'integer', 'min:0'],
            'compare_at_price_irr' => ['nullable', 'integer', 'min:0'],
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
            if ($this->filled('compare_at_price_irr') && $this->integer('compare_at_price_irr') < $this->integer('price_irr')) {
                $validator->errors()->add('compare_at_price_irr', 'قیمت قبل از تخفیف نمی‌تواند کمتر از قیمت فروش باشد.');
            }
        }];
    }
}
