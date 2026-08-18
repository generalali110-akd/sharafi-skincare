<?php

namespace App\Http\Requests\Api\V1\Auth;

use App\Support\PersianArabicDigits;
use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => PersianArabicDigits::normalize((string) $this->input('code'))]);
        }
    }

    public function rules(): array
    {
        return [
            'challenge_id' => ['required', 'uuid'],
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
        ];
    }
}
