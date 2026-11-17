<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AiChatRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The message, and optionally which conversation it continues.
     *
     * No customer identifier: whose conversation it is comes from the token.
     * The conversation is looked up through the authenticated customer, so
     * naming someone else's is the same as naming one that does not exist.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'message' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
