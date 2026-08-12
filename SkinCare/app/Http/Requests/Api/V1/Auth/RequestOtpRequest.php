<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\IranMobile;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class RequestOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'max:24'],
            'name' => ['nullable', 'string', 'min:2', 'max:120'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! IranMobile::isValid((string) $this->input('mobile'))) {
                    $validator->errors()->add('mobile', 'شماره موبایل معتبر نیست.');
                }
            },
        ];
    }

    public function normalizedMobile(): string
    {
        return IranMobile::normalize((string) $this->input('mobile'));
    }
}
