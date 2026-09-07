<?php

namespace App\Http\Requests;

use App\Domain\Twin\ScenarioType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The assumptions, and only the assumptions.
 *
 * Note everything absent: no customer identifier, no income, no outgo, no
 * EMI, no surplus, no ratio, no score, no interest rate. The starting point
 * comes from the authenticated profile and the pricing from the catalogue,
 * so a client cannot hand FynnEdge a figure and have it treated as fact.
 */
class SimulateTwinRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(ScenarioType::ids())],

            // Loan.
            'product_id' => [
                Rule::requiredIf($this->isType(ScenarioType::Loan)),
                'string',
                'max:60',
            ],
            'amount' => [
                Rule::requiredIf($this->isType(ScenarioType::Loan)),
                'numeric',
                'min:1',
                'max:100000000',
                // Money is held in paise; a third decimal is not a rupee
                // amount FynnEdge can represent.
                'decimal:0,2',
            ],
            'tenure_months' => [
                Rule::requiredIf($this->isType(ScenarioType::Loan)),
                'integer',
                'min:1',
                'max:360',
            ],

            // Spending more or less. Negative is a cut, and allowed.
            'monthly_change' => [
                Rule::requiredIf($this->isType(ScenarioType::ExpenseChange)),
                'numeric',
                'between:-10000000,10000000',
                'decimal:0,2',
            ],

            // Setting money aside.
            'monthly_amount' => [
                Rule::requiredIf($this->isType(ScenarioType::SavingChange)),
                'numeric',
                'min:1',
                'max:10000000',
                'decimal:0,2',
            ],
            // Five years, matching the slider. FynnTwin answers "if I do
            // this, what changes?" — not "where will I be in twenty years".
            // A longer horizon would be projecting a saving habit nobody can
            // vouch for, which is forecasting rather than simulation.
            'months' => [
                Rule::requiredIf($this->isType(ScenarioType::SavingChange)),
                'integer',
                'min:1',
                'max:60',
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'type.in' => 'FynnTwin cannot simulate that yet.',
            'amount.decimal' => 'Enter an amount in rupees and paise.',
            'tenure_months.integer' => 'A term is a whole number of months.',
        ];
    }

    private function isType(ScenarioType $type): bool
    {
        return $this->input('type') === $type->value;
    }
}
