<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendOtpRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            // Indian mobile numbers: 10 digits starting 6-9.
            'mobile' => ['required', 'string', 'regex:/^[6-9]\d{9}$/'],
            'device_name' => ['sometimes', 'string', 'max:80'],
        ];
    }

    public function messages(): array
    {
        return [
            'mobile.required' => 'Enter your mobile number.',
            'mobile.regex' => 'Enter a valid 10-digit mobile number.',
        ];
    }
}
