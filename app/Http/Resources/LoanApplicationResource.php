<?php

namespace App\Http\Resources;

use App\Domain\Application\CustomerStatus;
use App\Domain\Lender\SubmissionOutcome;
use App\Domain\Money;
use App\Models\ApplicationEvent;
use App\Models\LoanApplication;
use App\Services\LoanApplicationService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read LoanApplication $resource
 */
class LoanApplicationResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $a = $this->resource;
        $status = $a->status();

        return [
            'id' => (string) $a->id,
            'reference' => $a->reference,

            'product_id' => $a->product_id,
            'product_name' => $a->product_name,
            'lender' => $a->lender,
            'product_category' => $a->product_category,

            // Exactly what the customer asked for.
            'amount' => Money::of($a->amount)->toJsonNumber(),
            'tenure_months' => $a->tenure_months,
            'interest_rate' => (float) $a->interest_rate,

            // And the figures they were shown when they decided.
            'emi' => Money::of($a->emi)->toJsonNumber(),
            'total_interest' => Money::of($a->total_interest)->toJsonNumber(),
            'total_payable' => Money::of($a->total_payable)->toJsonNumber(),
            'processing_fee' => Money::of($a->processing_fee)->toJsonNumber(),
            'projected_emi_ratio' => $a->projected_emi_ratio === null
                ? null
                : (float) $a->projected_emi_ratio,
            'match_category' => $a->match_category,

            'status' => $status->value,
            'status_label' => $status->label(),

            /*
             * One sentence for the customer, from the two states that are
             * actually true.
             *
             * Worked out in one place rather than on each screen: two
             * surfaces deriving it independently would eventually disagree
             * about what a lender had said.
             */
            'customer_status' => CustomerStatus::of(
                $status,
                $a->latestSubmission()?->outcome() ?? SubmissionOutcome::NotAttempted,
            )->toArray(),
            'next_action' => $status->nextAction(),
            'can_submit' => $status->canSubmit(),
            'can_cancel' => $status->canCancel(),
            'is_editable' => $status->isEditable(),

            // Whether anything actually left FynnEdge.
            'is_simulated' => (bool) $a->is_simulated,
            'gateway' => $a->gateway,
            'provider_reference' => $a->provider_reference,

            'acknowledged_at' => $a->acknowledged_at?->toIso8601String(),
            'acknowledgement_version' => $a->acknowledgement_version,
            'acknowledgements' => LoanApplicationService::acknowledgements(),

            'submitted_at' => $a->submitted_at?->toIso8601String(),
            'cancelled_at' => $a->cancelled_at?->toIso8601String(),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),

            'events' => $a->events->map(fn (ApplicationEvent $e) => [
                'label' => $e->label,
                'detail' => $e->detail ?? '',
                // 'fynnedge', 'simulated' or 'provider' — the app renders a
                // simulated event differently from a real one.
                'source' => $e->source,
                'at' => $e->occurred_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }
}
