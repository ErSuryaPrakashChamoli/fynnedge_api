<?php

namespace App\Http\Requests;

use App\Domain\Enums\EmploymentType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The one place a customer's financial figures are validated.
 *
 * The rules are static so the other route into this data — confirming a
 * figure FynnScan read off a document — runs through exactly these, rather
 * than a second copy that could drift. There is one definition of what a
 * plausible income is, and both paths obey it.
 */
class UpdateFinancialProfileRequest extends FormRequest
{
    /** A sanity ceiling; anything above this is a typo, not an income. */
    private const MAX_MONEY = 100000000;

    /** @return array<string, mixed> */
    public static function profileRules(): array
    {
        $money = ['numeric', 'min:0', 'max:'.self::MAX_MONEY];

        return [
            'monthly_income' => ['required', ...$money],
            'monthly_expenses' => ['required', ...$money],
            'existing_emi' => ['required', ...$money],
            'savings' => ['required', ...$money],
            'other_obligations' => ['sometimes', ...$money],
            'employment_type' => [
                'required', Rule::in(EmploymentType::ids()),
            ],
        ];
    }

    /** @return array<string, string> */
    public static function profileMessages(): array
    {
        return [
            '*.max' => 'That figure looks too large — please check it.',
            '*.min' => 'That figure cannot be negative.',
            'employment_type.in' => 'Choose one of the listed employment types.',
        ];
    }

    /**
     * Cross-field check: outgoings above income are usually a data-entry
     * mistake, and every downstream calculation would inherit it.
     *
     * @param  array<string, mixed>  $input
     */
    public static function checkOutgoings(Validator $validator, array $input): void
    {
        $validator->after(function (Validator $validator) use ($input) {
            $income = (float) ($input['monthly_income'] ?? 0);
            $outgo = (float) ($input['monthly_expenses'] ?? 0)
                + (float) ($input['existing_emi'] ?? 0)
                + (float) ($input['other_obligations'] ?? 0);

            if ($income > 0 && $outgo > $income * 3) {
                $validator->errors()->add(
                    'monthly_expenses',
                    'Your outgoings are far above your income. Please check these figures.',
                );
            }
        });
    }

    public function rules(): array
    {
        return self::profileRules();
    }

    public function messages(): array
    {
        return self::profileMessages();
    }

    public function withValidator(Validator $validator): void
    {
        self::checkOutgoings($validator, $this->all());
    }
}
