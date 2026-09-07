<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** Only the figures. The product cannot be swapped under an application. */
    public function rules(): array
    {
        return [
            'amount' => ['sometimes', 'numeric', 'min:1', 'max:1000000000'],
            'tenure_months' => ['sometimes', 'integer', 'min:1', 'max:360'],
        ];
    }
}
