<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Submission requires the acknowledgement to be made explicitly.
 *
 * It is deliberately not a consent toggle stored on the profile: this is the
 * customer saying, about this application, that their information is accurate
 * and that the provider decides. FynnEdge holds no bureau or lender consent,
 * and nothing here implies it does.
 */
class SubmitLoanApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'acknowledged' => ['required', 'accepted'],
        ];
    }

    public function messages(): array
    {
        return [
            'acknowledged.required' => 'Please confirm the two statements before submitting.',
            'acknowledged.accepted' => 'Please confirm the two statements before submitting.',
        ];
    }
}
