<?php

namespace App\Http\Requests;

use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportContextType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Asking FynnEdge for help.
 *
 * What is validated here is shape, not content. A customer describing their
 * own problem will write about money — "my loan amount is 500000", "my PAN
 * did not upload" — and rejecting that would be rejecting the request most
 * worth reading. The rules that matter elsewhere in FynnEdge, which stop
 * *FynnEdge* putting a customer's data somewhere it does not belong, are
 * the wrong instrument pointed at the customer's own words.
 *
 * So: length, presence, control characters, and an allowlist for the two
 * fields that name things — category and context type. What is deliberately
 * absent is any field a customer could use to decide their own status,
 * priority, reference, or whose case this is.
 */
class StoreSupportCaseRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(SupportCategory::ids())],

            'subject' => ['required', 'string', 'min:3', 'max:160'],
            'description' => ['required', 'string', 'min:10', 'max:4000'],

            // An allowlisted type, never a model class name.
            'context_type' => ['sometimes', 'nullable', Rule::in(SupportContextType::ids())],

            // Digits only. Ownership is checked afterwards by
            // ContextResolver, through the customer's own relation.
            'context_id' => ['sometimes', 'nullable', 'string', 'max:20'],
        ];
    }

    public function messages(): array
    {
        return [
            'category.in' => 'Choose what your request is about.',
            'subject.required' => 'Give your request a short title.',
            'subject.min' => 'Add a few more words to the title.',
            'description.required' => 'Tell us what you need help with.',
            'description.min' => 'Tell us a little more so we can help.',
            'description.max' => 'That is longer than we can take. Please shorten it.',
            'context_type.in' => 'That is not something we can attach to a request.',
        ];
    }

    /**
     * Trimmed and stripped of control characters before validation.
     *
     * Control characters are removed rather than rejected: a customer
     * pasting from another app should not be told their perfectly ordinary
     * sentence is invalid because of an invisible byte in it. Newlines and
     * tabs survive, because people write in paragraphs.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(array_filter([
            'subject' => $this->clean($this->input('subject'), false),
            'description' => $this->clean($this->input('description'), true),
        ], fn ($v) => $v !== null));
    }

    private function clean(mixed $value, bool $keepNewlines): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $pattern = $keepNewlines
            ? '/[^\P{C}\n\t]+/u'
            : '/\p{C}+/u';

        $clean = preg_replace($pattern, $keepNewlines ? '' : ' ', $value) ?? $value;

        // Runs of blank lines collapse; a title becomes one line.
        $clean = $keepNewlines
            ? preg_replace("/\n{3,}/", "\n\n", $clean)
            : preg_replace('/\s+/u', ' ', $clean);

        return trim((string) $clean);
    }
}
