<?php

namespace App\Services\Decisions;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use App\Models\DecisionContext;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a decision context comes into existence.
 *
 * Insert-only. A decision record is evidence of what was shown at a moment,
 * and evidence that can be edited is not evidence.
 *
 * Only ever called from a real customer action — a simulation they ran, a
 * comparison they asked for. Never from a read path: a customer opening
 * their own decision history must not thereby create a decision.
 */
class DecisionRecorder
{
    public function __construct(private readonly DecisionKey $keys) {}

    /**
     * Records an exploration, or recognises one already recorded.
     *
     * Duplicates are prevented by the unique index through insertOrIgnore —
     * the mechanism Modules 29 to 31 use, for the same reason. A customer
     * dragging a slider fires the same simulation repeatedly, and a
     * read-then-write has a window between the two statements that a
     * repeated request walks straight into.
     */
    public function record(
        User $user,
        DecisionType $type,
        DecisionSnapshot $snapshot,
        ?string $productCode = null,
        ?int $productVersionId = null,
        ?Carbon $occurredAt = null,
    ): RecordedDecision {
        $now = $occurredAt ?? now();
        $key = $this->keys->for($type, $snapshot, $productCode, $now);

        $inserted = DB::table('decision_contexts')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'type' => $type->value,
            'product_code' => $productCode,
            'product_version_id' => $productVersionId,
            'snapshot' => json_encode($snapshot->toArray()),
            'idempotency_key' => $key,
            'occurred_at' => $now,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        /** @var DecisionContext $decision */
        $decision = DecisionContext::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $key)
            ->firstOrFail();

        return new RecordedDecision($decision, $inserted === 1);
    }
}
