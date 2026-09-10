<?php

namespace App\Services\Intelligence;

use App\Domain\Intelligence\FinancialMomentType;
use App\Models\FinancialMoment;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a financial moment comes into existence.
 *
 * Insert-only. There is no update method and no regenerate method, because
 * a moment is a historical fact: reaching half a goal happened, and it goes
 * on having happened after the customer spends the money again.
 *
 * Duplicates are prevented by the unique index on
 * (user_id, idempotency_key), reached through insertOrIgnore — the same
 * mechanism Modules 29 and 30 use, for the same reason. A read-then-write
 * has a window between the two statements, and a customer pulling to
 * refresh their home screen aims straight at it. Eight refreshes must
 * produce one "you reached 50%", and only the database can decide that.
 */
class MomentRecorder
{
    /**
     * Records a moment, or recognises that it is already recorded.
     *
     * Returns whether this call was the one that created it — which is what
     * a caller needs in order to decide whether anything further should
     * happen, such as Module 29 considering a notification.
     */
    public function record(
        User $user,
        FinancialMomentType $type,
        string $title,
        string $description,
        string $idempotencyKey,
        string $rule,
        ?string $evidence = null,
        ?string $contextType = null,
        ?string $contextReference = null,
        ?Carbon $occurredAt = null,
    ): RecordedMoment {
        $now = now();

        $inserted = DB::table('financial_moments')->insertOrIgnore([
            'user_id' => $user->getKey(),
            'type' => $type->value,
            'importance' => $type->importance()->value,
            'title' => $title,
            'description' => $description,
            'context_type' => $contextType,
            'context_reference' => $contextReference,
            'rule' => $rule,
            'evidence' => $evidence,
            'idempotency_key' => $idempotencyKey,
            'occurred_at' => $occurredAt ?? $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        /** @var FinancialMoment $moment */
        $moment = FinancialMoment::query()
            ->where('user_id', $user->getKey())
            ->where('idempotency_key', $idempotencyKey)
            ->firstOrFail();

        return new RecordedMoment($moment, $inserted === 1);
    }
}
