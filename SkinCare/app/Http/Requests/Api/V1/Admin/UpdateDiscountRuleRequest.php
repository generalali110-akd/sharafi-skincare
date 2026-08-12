<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Models\DiscountRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class UpdateDiscountRuleRequest extends FormRequest
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
        $rule = $this->route('discountRule');

        return [
            'code' => ['sometimes', 'string', 'min:3', 'max:64', 'regex:/^[A-Z0-9_-]+$/', Rule::unique('discount_rules', 'code')->ignore($rule)],
            'name' => ['sometimes', 'string', 'min:2', 'max:160'],
            'kind' => ['sometimes', Rule::in(['fixed', 'percentage'])],
            'amount_irr' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'percentage_bps' => ['sometimes', 'nullable', 'integer', 'between:1,10000'],
            'value' => ['prohibited'],
            'min_subtotal_irr' => ['sometimes', 'integer', 'min:0'],
            'max_discount_irr' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'starts_at' => ['sometimes', 'nullable', 'date'],
            'ends_at' => ['sometimes', 'nullable', 'date'],
            'usage_limit_total' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'usage_limit_per_user' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'is_active' => ['sometimes', 'boolean'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $rule = $this->route('discountRule');
            if (! $rule instanceof DiscountRule
                || $validator->errors()->hasAny(['kind', 'amount_irr', 'percentage_bps', 'starts_at', 'ends_at'])) {
                return;
            }

            $kind = (string) $this->input('kind', $rule->kind->value);
            $kindChanged = $this->has('kind') && $kind !== $rule->kind->value;

            if ($kind === 'fixed') {
                if (($kindChanged || $this->has('amount_irr')) && ! $this->filled('amount_irr')) {
                    $validator->errors()->add('amount_irr', 'مبلغ تخفیف ثابت الزامی است.');
                }
                if ($this->filled('percentage_bps')) {
                    $validator->errors()->add('percentage_bps', 'برای تخفیف ثابت، درصد ارسال نکنید.');
                }
            }

            if ($kind === 'percentage') {
                if (($kindChanged || $this->has('percentage_bps')) && ! $this->filled('percentage_bps')) {
                    $validator->errors()->add('percentage_bps', 'درصد تخفیف الزامی است.');
                }
                if ($this->filled('amount_irr')) {
                    $validator->errors()->add('amount_irr', 'برای تخفیف درصدی، مبلغ ثابت ارسال نکنید.');
                }
            }

            $startsAt = $this->has('starts_at')
                ? ($this->input('starts_at') === null ? null : Carbon::parse((string) $this->input('starts_at')))
                : $rule->starts_at;
            $endsAt = $this->has('ends_at')
                ? ($this->input('ends_at') === null ? null : Carbon::parse((string) $this->input('ends_at')))
                : $rule->ends_at;

            if ($startsAt !== null && $endsAt !== null && $endsAt->lte($startsAt)) {
                $validator->errors()->add('ends_at', 'زمان پایان باید بعد از زمان شروع باشد.');
            }
        }];
    }
}
