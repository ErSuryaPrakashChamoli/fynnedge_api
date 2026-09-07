<?php

namespace App\Http\Requests;

use App\Domain\Enums\GoalCategory;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreFinancialGoalRequest extends FormRequest
{
    public function rules(): array
    {
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'title' => [$required, 'string', 'min:2', 'max:120'],
            'category' => [$required, Rule::in(GoalCategory::ids())],
            'target_amount' => [$required, 'numeric', 'min:1', 'max:1000000000'],
            'target_date' => [$required, 'date', 'after:today'],
            'saved_amount' => ['sometimes', 'numeric', 'min:0'],
            'note' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function messages(): array
    {
        return [
            'target_date.after' => 'Pick a date in the future.',
            'target_amount.min' => 'Set a target worth aiming at.',
            'category.in' => 'That is not one of the available goal types.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $target = (float) $this->input('target_amount', 0);
            $saved = (float) $this->input('saved_amount', 0);

            if ($target > 0 && $saved > $target) {
                $validator->errors()->add(
                    'saved_amount',
                    'You have already saved more than the target. Raise the target instead.',
                );
            }
        });
    }
}
