<?php

namespace App\Services\Decisions;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionType;
use Illuminate\Support\Carbon;

/**
 * What makes two explorations the same exploration.
 *
 * The type, the figures, the product and the day. Everything about what was
 * shown, plus when — which means:
 *
 * A customer dragging the amount slider fires a simulation on every step,
 * and the ones that land on the same figures are one exploration rather
 * than forty. That is not a performance concern; it is what makes the
 * history readable. Forty identical rows is not a record of anything.
 *
 * A customer running the same simulation next month gets a second record,
 * because the day is part of the key. That is deliberate: exploring the
 * same option again weeks later is a different act, and their circumstances
 * may have moved underneath it.
 *
 * And any change to the figures — a different amount, a different tenure, a
 * changed income — is a different exploration, because the snapshot is
 * hashed whole.
 */
class DecisionKey
{
    public function for(
        DecisionType $type,
        DecisionSnapshot $snapshot,
        ?string $productCode,
        Carbon $at,
    ): string {
        $values = $snapshot->toArray();

        // Ordered, so two identical explorations cannot hash differently
        // because a producer built the array in a different order.
        ksort($values);

        return $type->value.':'.hash('sha256', implode('|', [
            $productCode ?? '',
            json_encode($values),
            $at->toDateString(),
        ]));
    }
}
