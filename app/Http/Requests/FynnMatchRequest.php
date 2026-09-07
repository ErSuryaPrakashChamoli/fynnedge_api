<?php

namespace App\Http\Requests;

use App\Domain\Enums\GoalCategory;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FynnMatchRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'amount' => ['required', 'numeric', 'min:1', 'max:1000000000'],
            'tenure_months' => ['required', 'integer', 'min:1', 'max:360'],
            // Purposes reuse the goal vocabulary the app already sends.
            'purpose' => ['sometimes', 'nullable', Rule::in(GoalCategory::ids())],
            'preferred_emi' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'amount.required' => 'Tell us how much you need.',
            'amount.min' => 'Enter an amount worth borrowing.',
            'tenure_months.max' => 'The longest term we can price is 30 years.',
        ];
    }
}
