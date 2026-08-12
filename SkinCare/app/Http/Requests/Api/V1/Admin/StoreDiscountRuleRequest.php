<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class StoreDiscountRuleRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => Str::upper(trim((string) $this->input('code')))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('discount_rules', 'code')],
            'name' => ['required', 'string', 'min:2', 'max:160'],
            'kind' => ['required', Rule::in(['fixed', 'percentage'])],
            'amount_irr' => ['nullable', 'integer', 'min:1'],
            'percentage_bps' => ['nullable', 'integer', 'between:1,10000'],
            'value' => ['prohibited'],
            'min_subtotal_irr' => ['sometimes', 'integer', 'min:0'],
            'max_discount_irr' => ['nullable', 'integer', 'min:1'],
            'starts_at' => ['nullable', 'date'],
            'ends_at' => ['nullable', 'date'],
            'usage_limit_total' => ['nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            if ($validator->errors()->hasAny(['kind', 'amount_irr', 'percentage_bps', 'starts_at', 'ends_at'])) {
                return;
            }

            $kind = $this->input('kind');
            if ($kind === 'fixed') {
                if (! $this->filled('amount_irr')) {
                    $validator->errors()->add('amount_irr', 'مبلغ تخفیف ثابت الزامی است.');
                }
                if ($this->filled('percentage_bps')) {
                    $validator->errors()->add('percentage_bps', 'برای تخفیف ثابت، درصد ارسال نکنید.');
                }
            }

            if ($kind === 'percentage') {
                if (! $this->filled('percentage_bps')) {
                    $validator->errors()->add('percentage_bps', 'درصد تخفیف الزامی است.');
                }
                if ($this->filled('amount_irr')) {
                    $validator->errors()->add('amount_irr', 'برای تخفیف درصدی، مبلغ ثابت ارسال نکنید.');
                }
            }

            $startsAt = $this->filled('starts_at') ? Carbon::parse((string) $this->input('starts_at')) : null;
            $endsAt = $this->filled('ends_at') ? Carbon::parse((string) $this->input('ends_at')) : null;
            if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
                $validator->errors()->add('ends_at', 'زمان پایان باید بعد از زمان شروع باشد.');
            }
        }];
    }
}
