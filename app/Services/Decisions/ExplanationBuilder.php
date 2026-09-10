<?php

namespace App\Services\Decisions;

use App\Domain\Decisions\DecisionEntry;
use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionSource;
use App\Domain\Decisions\DecisionType;
use App\Domain\Decisions\Explanation;
use App\Domain\Decisions\ExplanationFactor;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Money;

/**
 * Why FynnEdge showed what it showed.
 *
 * Every explanation is built from figures already recorded on the decision,
 * never recalculated. That is the point: a decision from six months ago has
 * to explain itself with the rate it was actually shown, and recomputing it
 * from today's catalogue would produce a confident explanation of something
 * that never happened.
 *
 * Nothing here predicts, recommends or reassures. An explanation says what
 * went in, what came out and what rule joined them — and where FynnEdge
 * could not work something out, it says so rather than leaving a gap a
 * customer would read as a pass.
 */
class ExplanationBuilder
{
    /** @return list<Explanation> */
    public function for(DecisionEntry $entry): array
    {
        return match ($entry->type) {
            DecisionType::Simulation => array_values(array_filter([
                $this->emi($entry->snapshot, $entry->isSimulated),
                $this->affordability($entry->snapshot),
            ])),

            DecisionType::Comparison => [$this->comparison($entry->snapshot)],

            DecisionType::ApplicationStarted,
            DecisionType::ApplicationSubmitted => array_values(array_filter([
                $this->emi($entry->snapshot, false),
                $this->applicationTerms($entry->snapshot),
            ])),
        };
    }

    /**
     * How the EMI was arrived at.
     *
     * The three figures that produce it and the figure itself. No claim
     * that it is affordable, manageable or a good idea — that is a
     * different question and the next explanation answers as much of it as
     * FynnEdge can.
     */
    private function emi(DecisionSnapshot $s, bool $simulated): ?Explanation
    {
        if (! $s->has('emi') || ! $s->has('amount')) {
            return null;
        }

        $calculated = $simulated ? DecisionSource::Simulated : DecisionSource::Calculated;

        return new Explanation(
            title: 'How the monthly payment was worked out',
            rule: 'A monthly payment comes from three things: how much is '
                .'borrowed, the annual rate, and how many months it runs for.',
            factors: array_values(array_filter([
                new ExplanationFactor(
                    'Amount',
                    $this->rupees($s->get('amount')),
                    DecisionSource::CustomerProvided,
                ),
                $s->has('interest_rate') ? new ExplanationFactor(
                    'Interest rate',
                    $this->percent($s->get('interest_rate')).'% a year',
                    DecisionSource::CataloguePublished,
                    // Adds to the source label rather than repeating it —
                    // the screen shows both, one after the other.
                    'FynnEdge did not set it.',
                ) : null,
                $s->has('tenure_months') ? new ExplanationFactor(
                    'Term',
                    $s->get('tenure_months').' months',
                    DecisionSource::CustomerProvided,
                ) : null,
            ])),
            result: new ExplanationFactor(
                'Monthly payment',
                $this->rupees($s->get('emi')),
                $calculated,
                $simulated ? 'A what-if. Nothing was applied for.' : null,
            ),
        );
    }

    /**
     * What the payment would do to their outgoings.
     *
     * Stated as a ratio against FynnEdge's own reference, and labelled as
     * FynnEdge's rather than as a lender rule — because it is. No lender
     * has told FynnEdge what they will accept, so nothing here says whether
     * one would.
     */
    private function affordability(DecisionSnapshot $s): ?Explanation
    {
        if (! $s->has('projected_emi_ratio') || ! $s->has('monthly_income')) {
            return null;
        }

        $projected = (float) $s->get('projected_emi_ratio');

        return new Explanation(
            title: 'What that would do to your monthly commitments',
            rule: 'FynnEdge measures repayments against '
                .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                .'% of monthly income. That is FynnEdge\'s own reference, '
                .'not a lender\'s rule — no lender has told us what they '
                .'would accept.',
            factors: array_values(array_filter([
                new ExplanationFactor(
                    'Monthly income',
                    $this->rupees($s->get('monthly_income')),
                    DecisionSource::CustomerProvided,
                    'As it was at the time.',
                ),
                $s->has('existing_emi') ? new ExplanationFactor(
                    'Repayments already committed',
                    $this->rupees($s->get('existing_emi')),
                    DecisionSource::CustomerProvided,
                    'As it was at the time.',
                ) : null,
                $s->has('emi_ratio') ? new ExplanationFactor(
                    'Share of income before this',
                    $this->percent($s->get('emi_ratio')).'%',
                    DecisionSource::Calculated,
                ) : null,
            ])),
            result: new ExplanationFactor(
                'Share of income with this added',
                $this->percent($projected).'%',
                DecisionSource::Calculated,
            ),

            /*
             * Said out loud rather than left as a gap.
             *
             * A customer looking at an affordability explanation with
             * nothing about approval could reasonably infer that FynnEdge
             * had checked. It has not, and it cannot.
             */
            notAssessed: [
                'Whether a lender would approve this. No lender has told '
                .'FynnEdge what they accept, so nothing here is a view on '
                .'that.',
            ],
        );
    }

    /**
     * What was put side by side.
     *
     * The products, and the fact that FynnEdge chose none of them. There is
     * no winner here because there was no winner: a comparison shows things
     * together, and reading a preference out of that would be inventing the
     * customer's opinion.
     */
    private function comparison(DecisionSnapshot $s): Explanation
    {
        $codes = $s->get('product_codes') ?? [];

        return new Explanation(
            title: 'What you compared',
            rule: 'FynnEdge showed these together, on the same criteria. It '
                .'picked no winner and recorded no preference.',
            factors: array_map(
                fn ($code) => new ExplanationFactor(
                    'Option',
                    (string) $code,
                    DecisionSource::CataloguePublished,
                ),
                is_array($codes) ? $codes : [],
            ),
            notAssessed: [
                'Which of these suits you best. That depends on things '
                .'FynnEdge has not been told, and it did not decide.',
            ],
        );
    }

    /** The terms the application carries, exactly as it carries them. */
    private function applicationTerms(DecisionSnapshot $s): ?Explanation
    {
        if (! $s->has('total_payable')) {
            return null;
        }

        return new Explanation(
            title: 'What this would cost in total',
            rule: 'The total is every monthly payment added together, plus '
                .'the lender\'s fee where one is published.',
            factors: array_values(array_filter([
                $s->has('total_interest') ? new ExplanationFactor(
                    'Interest over the term',
                    $this->rupees($s->get('total_interest')),
                    DecisionSource::Calculated,
                ) : null,
                $s->has('processing_fee') ? new ExplanationFactor(
                    'Processing fee',
                    $this->rupees($s->get('processing_fee')),
                    DecisionSource::CataloguePublished,
                ) : null,
            ])),
            result: new ExplanationFactor(
                'Total payable',
                $this->rupees($s->get('total_payable')),
                DecisionSource::Calculated,
                'As shown at the time. A later change to the catalogue does '
                .'not change what you were shown.',
            ),
        );
    }

    /**
     * Money, formatted the way FynnEdge formats money everywhere else.
     *
     * Through Money::toIndianString rather than number_format, because the
     * grouping differs — ₹1,50,000 against ₹150,000 — and a customer seeing
     * one convention on their score screen and another in an explanation of
     * it is being shown two products.
     */
    private function rupees(mixed $value): string
    {
        return Money::of((float) $value)->toIndianString();
    }

    private function percent(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }
}
