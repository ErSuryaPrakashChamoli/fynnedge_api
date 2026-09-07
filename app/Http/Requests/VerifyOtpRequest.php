<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'mobile' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'reference' => ['required', 'string', 'max:64'],
            'code' => ['required', 'digits:'.config('fynnedge.otp.length')],
            'device_name' => ['sometimes', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.digits' => 'Enter all '.config('fynnedge.otp.length').' digits.',
            'mobile.regex' => 'Enter a valid 10-digit mobile number.',
        ];
    }
}
