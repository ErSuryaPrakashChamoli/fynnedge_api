<?php

namespace App\Domain\Provider\Fixture;

use App\Domain\Provider\ProviderAdapter;
use App\Domain\Provider\ProviderFeed;
use App\Domain\Provider\ProviderFeedKind;
use App\Domain\Provider\ProviderUnavailable;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * A FICTIONAL provider, used to prove the integration boundary works.
 *
 * NO LIVE PROVIDER IS CONNECTED TO FYNNEDGE. Nothing here reaches a network.
 * "Kestrel Financial" does not exist, and its products are invented. Every
 * version this adapter produces carries a sample provenance, so a product
 * that arrived this way can never be shown as a real offer.
 *
 * The rows below are deliberately awkward. They use the provider's own field
 * names, quote rates as basis points, count tenure in years, name categories
 * their own way and include a requirement FynnEdge cannot evaluate. If the
 * normalizer were doing nothing, none of it would survive.
 */
class FixtureProviderAdapter implements ProviderAdapter
{
    public const PROVIDER_CODE = 'kestrel_financial';

    /**
     * @param  list<array<string, mixed>>|null  $rows  Overrides the fixture,
     *                                                 for tests that need a
     *                                                 changed rate or a bad row.
     */
    public function __construct(
        private readonly ?array $rows = null,
        private readonly ?CarbonInterface $retrievedAt = null,
        private readonly bool $unavailable = false,
    ) {}

    public function providerCode(): string
    {
        return self::PROVIDER_CODE;
    }

    public function fetch(): ProviderFeed
    {
        if ($this->unavailable) {
            throw new ProviderUnavailable(
                self::PROVIDER_CODE,
                'the fixture was configured to be unreachable',
            );
        }

        return new ProviderFeed(
            providerCode: self::PROVIDER_CODE,
            // This provider states its whole catalogue every time, so a
            // product missing from it has genuinely been withdrawn.
            kind: ProviderFeedKind::Full,
            retrievedAt: $this->retrievedAt ?? Carbon::now(),
            rows: $this->rows ?? self::catalogue(),
        );
    }

    /** The provider's display name, as they publish it. */
    public static function providerName(): string
    {
        return 'Kestrel Financial (sample)';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function catalogue(): array
    {
        return [
            [
                // Their identifier, not ours.
                'productRef' => 'KF-PL-001',
                'productTitle' => 'Kestrel Personal Advance',
                // Their vocabulary for what it is.
                'segment' => 'CONSUMER_TERM_CREDIT',
                'status' => 'ACTIVE',

                // Rate in basis points: 1150 = 11.50%.
                'roiBasisPoints' => 1150,
                'feePct' => 1.25,
                'feeCeilingRupees' => 18000,
                'foreclosureTerms' => 'Nil after 6 EMIs',

                // Amounts in whole rupees.
                'amountFloor' => 200000,
                'amountCeiling' => 2500000,

                // Tenure in years, not months.
                'tenureYearsMin' => 1,
                'tenureYearsMax' => 6,

                'incomeFloorMonthly' => 35000,
                'applicantAgeMin' => 21,
                'applicantAgeMaxAtClosure' => 60,

                // A requirement FynnEdge holds no data for. It must survive
                // as unevaluated rather than quietly becoming a pass.
                'minimumBureauScore' => 700,

                'highlights' => [
                    'Rate is fixed for the full term',
                    'Fee is capped, so it does not scale with the amount',
                ],
                'watchOuts' => [
                    'The published minimum is high for smaller borrowing',
                ],
                'terms' => [
                    'Minimum monthly income Rs 35,000',
                    'Age 21 to 60 at closure',
                ],
            ],
            [
                'productRef' => 'KF-BL-002',
                'productTitle' => 'Kestrel Business Term',
                'segment' => 'SME_TERM_CREDIT',
                'status' => 'ACTIVE',

                'roiBasisPoints' => 1325,
                'feePct' => 1.0,
                'feeCeilingRupees' => null,
                'foreclosureTerms' => 'Nil after 12 EMIs',

                'amountFloor' => 500000,
                'amountCeiling' => 5000000,
                'tenureYearsMin' => 2,
                'tenureYearsMax' => 7,

                // This one publishes no income or age rule at all. Those
                // must stay unknown, and FynnMatch must report them as
                // unchecked rather than passed.
                'incomeFloorMonthly' => null,
                'applicantAgeMin' => null,
                'applicantAgeMaxAtClosure' => null,

                'highlights' => ['Longer terms are available for larger amounts'],
                'watchOuts' => ['Security may be required above the midpoint'],
                'terms' => [],
            ],
        ];
    }
}
