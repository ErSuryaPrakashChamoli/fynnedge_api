<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Note what is absent: no customer identifier. Whose application this is
     * comes from the token, and a user_id in the payload has no rule here and
     * never reaches the model.
     */
    public function rules(): array
    {
        return [
            'product_id' => ['required', 'string', 'max:60'],
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:360'],
        ];
    }
}
