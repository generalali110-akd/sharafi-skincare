<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\OrderStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateOrderStatusRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'expected_status' => ['required', Rule::enum(OrderStatus::class)],
            'status' => [
                'required',
                Rule::enum(OrderStatus::class)->only([
                    OrderStatus::Processing,
                    OrderStatus::Shipped,
                    OrderStatus::Delivered,
                    OrderStatus::Cancelled,
                    OrderStatus::RefundPending,
                    OrderStatus::Refunded,
                ]),
            ],
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }
}
