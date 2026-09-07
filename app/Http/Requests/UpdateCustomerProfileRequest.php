<?php

namespace App\Http\Requests;

use App\Domain\Enums\GoalCategory;
use App\Domain\Enums\Intent;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerProfileRequest extends FormRequest
{
    /** No angle brackets and no control characters. */
    private const NO_MARKUP = 'regex:/^[^<>\x00-\x1F\x7F]*$/u';

    /**
     * Names and places, in any script.
     *
     * The one thing refused is markup: no name contains angle brackets, and
     * a stored `<script>` is a payload waiting for the first thing that
     * renders a customer's name as HTML rather than as text — an email, an
     * export, an admin view. Unicode is untouched, so every real name still
     * goes in exactly as written.
     */
    public function rules(): array
    {
        return self::profileRules();
    }

    /**
     * Static so the other route into this data — resolving a difference a
     * verification service reported — runs through exactly these rules
     * rather than a second copy that could drift.
     *
     * @return array<string, mixed>
     */
    public static function profileRules(): array
    {
        return [
            'full_name' => [
                'sometimes', 'nullable', 'string', 'min:2', 'max:120',
                self::NO_MARKUP,
            ],
            'age' => ['sometimes', 'nullable', 'integer', 'min:18', 'max:100'],
            'city' => ['sometimes', 'nullable', 'string', 'max:80', self::NO_MARKUP],
            'email' => ['sometimes', 'nullable', 'email:rfc', 'max:190'],
            'intent' => ['sometimes', 'nullable', Rule::in(Intent::ids())],
            'primary_goal' => [
                'sometimes', 'nullable', Rule::in(GoalCategory::ids()),
            ],
        ];
    }

    public function messages(): array
    {
        return self::profileMessages();
    }

    /** @return array<string, string> */
    public static function profileMessages(): array
    {
        return [
            'age.min' => 'You must be at least 18 to use FynnEdge.',
            'full_name.min' => 'Please enter your full name.',
            'full_name.regex' => 'Please use letters only — no < or > characters.',
            'city.regex' => 'Please use letters only — no < or > characters.',
            'intent.in' => 'That is not one of the available options.',
            'primary_goal.in' => 'That is not one of the available goals.',
        ];
    }
}
