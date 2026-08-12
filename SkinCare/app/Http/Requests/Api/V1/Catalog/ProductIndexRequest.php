<?php

namespace App\Http\Requests\Api\V1\Catalog;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:120'],
            'category' => ['nullable', 'string', 'max:160'],
            'brand' => ['nullable', 'string', 'max:160'],
            'min_price' => ['nullable', 'integer', 'min:0'],
            'max_price' => ['nullable', 'integer', 'min:0'],
            'sort' => ['nullable', Rule::in(['default', 'newest', 'price-asc', 'price-desc'])],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:48'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $min = $this->integer('min_price');
                $max = $this->integer('max_price');

                if ($this->filled('min_price') && $this->filled('max_price') && $min > $max) {
                    $validator->errors()->add('max_price', 'حداکثر قیمت باید بزرگ‌تر یا مساوی حداقل قیمت باشد.');
                }
            },
        ];
    }
}
