<?php

namespace App\Http\Resources;

use App\Domain\Financial\FinancialEngine;
use App\Models\FinancialGoal;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read FinancialGoal $resource
 */
class FinancialGoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $g = $this->resource;
        $metrics = app(FinancialEngine::class)->goalMetrics($g);

        return [
            'id' => (string) $g->id,
            'title' => $g->title,
            'category' => $g->category,
            'target_amount' => $metrics->target->toJsonNumber(),
            'target_date' => $g->target_date?->toIso8601String(),
            'saved_amount' => $metrics->saved->toJsonNumber(),
            'note' => $g->note ?? '',
            'status' => $g->status,

            'derived' => [
                'progress' => $metrics->progress(),
                'months_remaining' => $metrics->monthsRemaining,
                'monthly_required' => $metrics->monthlyRequired->toJsonNumber(),
                'remaining_amount' => $metrics->remaining->toJsonNumber(),
                'is_complete' => $metrics->isComplete,
                'is_overdue' => $metrics->isOverdue,
            ],
        ];
    }
}
