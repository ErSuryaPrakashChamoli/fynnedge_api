<?php

namespace App\Domain\Credit\Fixture;

use App\Domain\Credit\CreditBureau;
use App\Domain\Credit\CreditNormalizationFailed;
use App\Domain\Credit\CreditNormalizer;
use App\Domain\Credit\CreditProfile;
use Illuminate\Support\Carbon;

/**
 * The fixture bureau's response, in FynnEdge's terms.
 *
 *   creditScore  742                → score
 *   scoreType    SAMPLE-SCORE-V1    → score_model
 *   scoreRange   300–900            → scale
 *   reportDate   2026-09-06T10:15   → obtained_at
 *
 * And then it stops. The name, the date of birth, the PAN, the mobile
 * number, the addresses, the employer, the account numbers, the balances and
 * the enquiry history are all read past and none of them go anywhere. Only
 * four values leave this class.
 *
 * A score with no stated range is refused rather than assumed to be 300–900.
 * Comparing a number on an unknown scale against a documented minimum
 * produces a confident answer that may be entirely wrong.
 */
class FixtureCreditNormalizer implements CreditNormalizer
{
    public function normalize(array $response): CreditProfile
    {
        $score = $response['creditScore'] ?? null;

        if (! is_int($score)) {
            throw new CreditNormalizationFailed(
                'the response carries no usable credit score.',
            );
        }

        $model = $response['scoreType'] ?? null;

        if (! is_string($model) || trim($model) === '') {
            throw new CreditNormalizationFailed(
                'the response does not say which score model was used, and a '
                .'score means nothing without one.',
            );
        }

        $range = $response['scoreRange'] ?? null;
        $min = $range['minimum'] ?? null;
        $max = $range['maximum'] ?? null;

        if (! is_int($min) || ! is_int($max) || $min >= $max) {
            throw new CreditNormalizationFailed(
                'the response does not state the scale this score sits on. '
                .'Assuming one would compare it against a documented minimum '
                .'that may be measured differently.',
            );
        }

        if ($score < $min || $score > $max) {
            throw new CreditNormalizationFailed(
                'the score falls outside the scale the bureau stated for it.',
            );
        }

        $obtainedAt = $response['reportDate'] ?? null;

        if (! is_string($obtainedAt)) {
            throw new CreditNormalizationFailed(
                'the response does not say when it was produced.',
            );
        }

        return new CreditProfile(
            bureau: CreditBureau::Fixture,
            score: $score,
            scoreModel: $model,
            scoreMin: $min,
            scoreMax: $max,
            obtainedAt: Carbon::parse($obtainedAt),
        );
    }
}
