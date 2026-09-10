<?php

namespace App\Domain\Decisions;

/**
 * Why FynnEdge showed what it showed.
 *
 * A heading, the figures that went into it, and the rule that combined
 * them. Nothing here predicts, recommends or reassures: an explanation says
 * what was calculated and from what, and stops.
 *
 * `rule` is stated in the customer's terms — "an EMI is worked out from the
 * amount, the rate and the term" — rather than as an implementation detail.
 * A customer asking why a number is what it is deserves an answer, not a
 * formula they cannot check.
 */
final class Explanation
{
    /** @param list<ExplanationFactor> $factors */
    public function __construct(
        public readonly string $title,
        public readonly string $rule,
        public readonly array $factors,

        /** What came out, when there is a single result. */
        public readonly ?ExplanationFactor $result = null,

        /**
         * Something FynnEdge could not work out.
         *
         * Present rather than omitted, because "not assessed" and "passed"
         * are different answers and only one of them is true.
         *
         * @var list<string>
         */
        public readonly array $notAssessed = [],
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return array_filter([
            'title' => $this->title,
            'rule' => $this->rule,
            'factors' => array_map(
                fn (ExplanationFactor $f) => $f->toArray(),
                $this->factors,
            ),
            'result' => $this->result?->toArray(),
            'not_assessed' => $this->notAssessed === [] ? null : $this->notAssessed,
        ], fn ($v) => $v !== null);
    }
}
