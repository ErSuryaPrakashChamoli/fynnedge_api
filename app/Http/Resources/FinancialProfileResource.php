<?php

namespace App\Http\Resources;

use App\Domain\Financial\FinancialEngine;
use App\Models\FinancialProfile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Derived figures come from FinancialEngine — one snapshot per response, not
 * one per field — so the app never has to recompute a definition the backend
 * already owns.
 *
 * @property-read FinancialProfile $resource
 */
class FinancialProfileResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $p = $this->resource;
        $snapshot = app(FinancialEngine::class)->snapshot($p);

        return [
            'monthly_income' => $snapshot->income->toJsonNumber(),
            'monthly_expenses' => $snapshot->expenses->toJsonNumber(),
            'existing_emi' => $snapshot->existingEmi->toJsonNumber(),
            'savings' => $snapshot->savings->toJsonNumber(),
            'other_obligations' => $snapshot->otherObligations->toJsonNumber(),
            'employment_type' => $p->employment_type,

            'derived' => $snapshot->toDerivedArray(),
        ];
    }
}
