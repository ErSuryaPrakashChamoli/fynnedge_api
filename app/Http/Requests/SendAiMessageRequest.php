<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SendAiMessageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The message and nothing else.
     *
     * No customer identifier, no conversation owner, no context overrides:
     * everything about whose data is used is settled by the token before
     * this text is looked at.
     */
    public function rules(): array
    {
        return [
            'text' => ['required', 'string', 'min:1', 'max:2000'],
        ];
    }
}
