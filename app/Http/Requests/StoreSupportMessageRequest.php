<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Adding information to a request that is already open.
 *
 * One field. There is deliberately no `author_type` and no `author_id`:
 * a customer able to send either could author an advisor message on their
 * own case and then quote FynnEdge saying it. The server decides who wrote
 * this, every time, from the token.
 */
class StoreSupportMessageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'min:2', 'max:4000'],
        ];
    }

    public function messages(): array
    {
        return [
            'body.required' => 'Write what you would like to add.',
            'body.max' => 'That is longer than we can take. Please shorten it.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $body = $this->input('body');

        if (is_string($body)) {
            $clean = preg_replace('/[^\P{C}\n\t]+/u', '', $body) ?? $body;
            $clean = preg_replace("/\n{3,}/", "\n\n", $clean);

            $this->merge(['body' => trim((string) $clean)]);
        }
    }
}
