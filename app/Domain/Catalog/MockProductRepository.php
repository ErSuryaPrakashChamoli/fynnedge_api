<?php

namespace App\Domain\Catalog;

use App\Domain\Money;
use Illuminate\Support\Carbon;

/**
 * DEVELOPMENT CATALOGUE — fictional products, not live lender offers.
 *
 * Since Module 20 the rows below are assembled into CatalogueProducts:
 * an invented Provider, a stable product identity, a status, and one
 * ProductVersion carrying the pricing and eligibility. What comes out is
 * the same LoanProduct FynnMatch has always consumed, resolved for today.
 *
 * That indirection is the point. A real provider's catalogue produces the
 * same CatalogueProducts from different input, and nothing downstream of
 * this class can tell the difference except by reading the source, which
 * says plainly that these were written by FynnEdge.
 *
 * Every lender name here is invented so a screenshot can never be mistaken
 * for real market pricing. isSampleData() returns true, and the app labels
 * anything shown from this catalogue accordingly.
 *
 * `min_monthly_income`, `min_age` and `max_age_at_maturity` carry exactly the
 * values these products already stated in their `conditions` text; they are
 * structured here so FynnMatch can evaluate them instead of guessing. A
 * product that documents no such requirement leaves them null, and the
 * matching criterion reports itself unavailable rather than passing by
 * default.
 */
class MockProductRepository implements ProductRepository
{
    /** @var list<LoanProduct>|null */
    private ?array $cache = null;

    /**
     * The catalogue as it is administered: providers, identities, statuses
     * and versions. Module 21 replaces this array with a database; nothing
     * outside this class changes when it does.
     *
     * @return list<CatalogueProduct>
     */
    public function catalogueProducts(): array
    {
        return array_map(
            fn (array $row) => $this->toCatalogueProduct($row),
            self::catalogue(),
        );
    }

    public function all(?string $category = null): array
    {
        // Resolved for today, and only what a customer may be offered. A
        // draft or withdrawn product, or one whose versions do not cover
        // today, is simply not here — it is never repaired into something
        // showable.
        $products = $this->cache ??= array_values(array_filter(array_map(
            fn (CatalogueProduct $p) => $p->isDiscoverable()
                ? $p->resolve()
                : null,
            $this->catalogueProducts(),
        )));

        if ($category === null) {
            return $products;
        }

        return array_values(array_filter(
            $products,
            fn (LoanProduct $p) => $p->category === $category,
        ));
    }

    public function find(string $id): ?LoanProduct
    {
        foreach ($this->all() as $product) {
            if ($product->id === $id) {
                return $product;
            }
        }

        return null;
    }

    public function isSampleData(): bool
    {
        return true;
    }

    /**
     * One catalogue row as an administered product.
     *
     * Every figure is carried across unchanged; this reshapes the record, it
     * does not reprice it. The single version runs from a date well before
     * FynnEdge existed and has no end, so "the terms today" is unambiguous.
     *
     * @param  array<string, mixed>  $row
     */
    private function toCatalogueProduct(array $row): CatalogueProduct
    {
        $provider = new Provider(
            code: $row['provider_code'],
            name: $row['lender'],
            type: ProviderType::from($row['provider_type']),
            isSample: true,
        );

        $version = new ProductVersion(
            persistedId: null,
            effectiveFrom: Carbon::parse(self::SAMPLE_EFFECTIVE_FROM),
            effectiveUntil: null,
            interestRate: (float) $row['interest_rate'],
            processingFeePercent: (float) $row['processing_fee_percent'],
            processingFeeCap: ($row['processing_fee_cap'] ?? null) !== null
                ? Money::of($row['processing_fee_cap'])
                : null,
            prepaymentCharge: $row['prepayment_charge'] ?? '',
            minAmount: Money::of($row['min_amount']),
            maxAmount: Money::of($row['max_amount']),
            minTenureMonths: (int) $row['min_tenure_months'],
            maxTenureMonths: (int) $row['max_tenure_months'],
            minMonthlyIncome: isset($row['min_monthly_income'])
                ? Money::of($row['min_monthly_income'])
                : null,
            minAge: $row['min_age'] ?? null,
            maxAgeAtMaturity: $row['max_age_at_maturity'] ?? null,
            fynnTrust: (int) $row['fynn_trust'],
            trustBreakdown: $row['trust_breakdown'] ?? [],
            lenderTag: $row['lender_tag'] ?? '',
            suitedReasons: $row['suited_reasons'] ?? [],
            considerations: $row['considerations'] ?? [],
            conditions: $row['conditions'] ?? [],
            source: ProductSource::sample(),
            minCreditScore: $row['min_credit_score'] ?? null,
        );

        return new CatalogueProduct(
            provider: $provider,
            code: $row['product_code'],
            name: $row['name'],
            category: $row['category'],
            status: ProductStatus::Active,
            versions: [$version],
            // The identity these products have always had, and which every
            // application ever made against them records.
            legacyId: $row['id'],
        );
    }

    /**
     * The day the sample catalogue's single version began.
     *
     * Far enough back that no application can predate it, so "which version
     * applied" has one answer for every record FynnEdge holds.
     */
    private const SAMPLE_EFFECTIVE_FROM = '2020-01-01';

    /** @return array{label: string, value: int, explanation: string} */
    private static function factor(string $label, int $value, string $why): array
    {
        return ['label' => $label, 'value' => $value, 'explanation' => $why];
    }

    /** @return list<array<string, mixed>> */
    private static function catalogue(): array
    {
        return [
            [
                'id' => 'prod_pl_a',
                'provider_code' => 'meridian_bank',
                'provider_type' => 'bank',
                'product_code' => 'personal_a',
                'min_monthly_income' => '40000.00',
                'min_age' => 23,
                'max_age_at_maturity' => 58,
                'lender' => 'Meridian Bank',
                'name' => 'Personal Loan A',
                'lender_tag' => 'Unsecured',
                'category' => 'personal',
                'min_amount' => 500000,
                'max_amount' => 1500000,
                'interest_rate' => 11.5,
                'min_tenure_months' => 12,
                'max_tenure_months' => 60,
                'processing_fee_percent' => 1.0,
                'processing_fee_cap' => 12000,
                'fynn_trust' => 86,
                'prepayment_charge' => 'Nil after 6 EMIs',
                'suited_reasons' => [
                    'The rate is among the lowest in your eligible set',
                    'Prepayment is free after six EMIs, so you can close early',
                    'Processing fee is capped at ₹12,000 regardless of loan size',
                ],
                'considerations' => [
                    'Requires 2 years of income proof',
                    'Rate quoted is the best-case rate for strong profiles',
                ],
                'conditions' => [
                    'Minimum monthly income ₹40,000',
                    'Age 23–58 at loan maturity',
                    'Foreclosure permitted after 6 EMIs at nil charge',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 88, 'At 11.5% this sits below the median rate for comparable personal loans in your band.'),
                    'fees' => self::factor('Fees', 84, 'A 1% processing fee capped at ₹12,000 keeps the one-time cost predictable.'),
                    'flexibility' => self::factor('Flexibility', 90, 'Free prepayment after six EMIs and tenure choices from 1 to 5 years.'),
                    'suitability' => self::factor('Suitability', 82, 'The amount range and EMI fit your stated income and surplus.'),
                    'transparency' => self::factor('Transparency', 86, 'All charges are published up front with no conditional add-ons.'),
                ],
            ],
            [
                'id' => 'prod_pl_b',
                'provider_code' => 'northline_finance',
                'provider_type' => 'nbfc',
                'product_code' => 'personal_b',
                'min_monthly_income' => '30000.00',
                'lender' => 'Northline Finance',
                'name' => 'Personal Loan B',
                'lender_tag' => 'Flexible eligibility',
                'category' => 'personal',
                'min_amount' => 300000,
                'max_amount' => 1200000,
                'interest_rate' => 12.5,
                'min_tenure_months' => 12,
                'max_tenure_months' => 72,
                'processing_fee_percent' => 1.5,
                'processing_fee_cap' => null,
                'fynn_trust' => 78,
                'prepayment_charge' => '2% of outstanding',
                'suited_reasons' => [
                    'Accepts a wider range of income profiles',
                    'Longer tenure available if you need a smaller EMI',
                ],
                'considerations' => [
                    'Rate is 1 percentage point above the best option in your set',
                    'Prepayment carries a 2% charge on the outstanding amount',
                    'No cap on the processing fee',
                ],
                'conditions' => [
                    'Minimum monthly income ₹30,000',
                    'Prepayment allowed after 12 EMIs at 2%',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 72, 'At 12.5% you pay noticeably more interest over the full tenure.'),
                    'fees' => self::factor('Fees', 68, 'The 1.5% processing fee is uncapped, so it scales with the loan.'),
                    'flexibility' => self::factor('Flexibility', 74, 'Tenure up to 72 months helps, but prepayment costs 2%.'),
                    'suitability' => self::factor('Suitability', 86, 'The published eligibility rules are wider than most in this set.'),
                    'transparency' => self::factor('Transparency', 80, 'Charges are disclosed, though the fee structure takes reading.'),
                ],
            ],
            [
                'id' => 'prod_bl_a',
                'provider_code' => 'arcus_capital',
                'provider_type' => 'nbfc',
                'product_code' => 'business_a',
                'lender' => 'Arcus Capital',
                'name' => 'Business Growth Loan',
                'lender_tag' => 'For business owners',
                'category' => 'business',
                'min_amount' => 500000,
                'max_amount' => 5000000,
                'interest_rate' => 13.25,
                'min_tenure_months' => 12,
                'max_tenure_months' => 84,
                'processing_fee_percent' => 1.75,
                'processing_fee_cap' => null,
                'fynn_trust' => 81,
                'prepayment_charge' => '3% before 24 EMIs, nil after',
                'suited_reasons' => [
                    'Built for self-employed and business borrowers like you',
                    'Amount range covers your ₹10L requirement comfortably',
                    'Tenure up to 7 years keeps the EMI manageable',
                ],
                'considerations' => [
                    'Business vintage of 3 years is required',
                    'Early prepayment within 2 years carries a 3% charge',
                ],
                'conditions' => [
                    'Business vintage minimum 3 years',
                    'GST returns for the last 4 quarters',
                    'Prepayment 3% before 24 EMIs',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 74, 'Business loans price higher than personal loans; 13.25% is fair for this segment.'),
                    'fees' => self::factor('Fees', 70, 'A 1.75% uncapped processing fee is on the higher side.'),
                    'flexibility' => self::factor('Flexibility', 78, 'Long tenure options, but prepayment is penalised for two years.'),
                    'suitability' => self::factor('Suitability', 94, 'Matches your business intent, amount and income profile closely.'),
                    'transparency' => self::factor('Transparency', 88, 'Terms and charges are clearly stated with no hidden conditions.'),
                ],
            ],
            [
                'id' => 'prod_bl_b',
                'provider_code' => 'united_credit_bank',
                'provider_type' => 'bank',
                'product_code' => 'sme_term',
                'lender' => 'United Credit Bank',
                'name' => 'SME Term Loan',
                'lender_tag' => 'Lowest business rate in this set',
                'category' => 'business',
                'min_amount' => 1000000,
                'max_amount' => 7500000,
                'interest_rate' => 12.4,
                'min_tenure_months' => 24,
                'max_tenure_months' => 84,
                'processing_fee_percent' => 1.0,
                'processing_fee_cap' => 25000,
                'fynn_trust' => 89,
                'prepayment_charge' => 'Nil after 12 EMIs',
                'suited_reasons' => [
                    'The lowest published rate in this sample set for a business loan',
                    'Processing fee is capped, saving you money at this loan size',
                    'Free prepayment after the first year',
                ],
                'considerations' => [
                    'Minimum loan amount is ₹10L, so this only works at your full ask',
                    'The published minimum is high, so smaller amounts are not served',
                ],
                'conditions' => [
                    'Audited financials for 2 years',
                    'Minimum turnover ₹50L',
                    'Collateral-free up to ₹25L',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 92, 'At 12.4% this is the lowest published business rate in this sample set.'),
                    'fees' => self::factor('Fees', 90, 'The ₹25,000 cap on a 1% fee is a meaningful saving at ₹10L+.'),
                    'flexibility' => self::factor('Flexibility', 86, 'Prepay free after 12 EMIs, with tenure up to 84 months.'),
                    'suitability' => self::factor('Suitability', 84, 'Well matched to your amount, though the process is slower.'),
                    'transparency' => self::factor('Transparency', 92, 'Full schedule of charges published, including all edge cases.'),
                ],
            ],
            [
                'id' => 'prod_hl_a',
                'provider_code' => 'sentinel_housing',
                'provider_type' => 'nbfc',
                'product_code' => 'home_a',
                'lender' => 'Sentinel Housing Finance',
                'name' => 'Home Loan Prime',
                'lender_tag' => 'Long tenure',
                'category' => 'home',
                'min_amount' => 2500000,
                'max_amount' => 50000000,
                'interest_rate' => 8.6,
                'min_tenure_months' => 60,
                'max_tenure_months' => 360,
                'processing_fee_percent' => 0.35,
                'processing_fee_cap' => 15000,
                'fynn_trust' => 91,
                'prepayment_charge' => 'Nil on floating rate',
                'suited_reasons' => [
                    'Home loans carry the lowest interest of any borrowing you can do',
                    'Floating-rate prepayment is free at any time',
                ],
                'considerations' => [
                    'Requires property documents and legal verification',
                    'Disbursal is linked to construction stage for under-construction property',
                ],
                'conditions' => [
                    'Property must be approved by the lender panel',
                    'Up to 80% of property value',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 96, 'At 8.6% this is the lowest published rate in this sample set.'),
                    'fees' => self::factor('Fees', 92, 'A 0.35% fee capped at ₹15,000 is very low relative to loan size.'),
                    'flexibility' => self::factor('Flexibility', 94, 'Free prepayment on floating rate and tenure up to 30 years.'),
                    'suitability' => self::factor('Suitability', 70, 'Only relevant if you are actually buying property.'),
                    'transparency' => self::factor('Transparency', 90, 'Rate resets and charges are documented clearly.'),
                ],
            ],
            [
                'id' => 'prod_lap_a',
                'provider_code' => 'trilo_finserv',
                'provider_type' => 'nbfc',
                'product_code' => 'lap_a',
                'lender' => 'Trilo Finserv',
                'name' => 'Loan Against Property',
                'lender_tag' => 'Secured',
                'category' => 'lap',
                'min_amount' => 1000000,
                'max_amount' => 20000000,
                'interest_rate' => 10.25,
                'min_tenure_months' => 36,
                'max_tenure_months' => 180,
                'processing_fee_percent' => 1.0,
                'processing_fee_cap' => null,
                'fynn_trust' => 83,
                'prepayment_charge' => 'Nil for individual borrowers',
                'suited_reasons' => [
                    'Cheaper than an unsecured business loan because it is secured',
                    'Higher amounts available against your property value',
                ],
                'considerations' => [
                    'Your property is at risk if repayments are missed',
                    'Valuation and legal checks add 2 to 3 weeks',
                ],
                'conditions' => [
                    'Property title must be clear and marketable',
                    'Up to 60% of property value',
                ],
                'trust_breakdown' => [
                    'cost' => self::factor('Cost', 86, 'Secured lending at 10.25% undercuts unsecured business rates.'),
                    'fees' => self::factor('Fees', 76, 'A 1% uncapped fee is standard for this product.'),
                    'flexibility' => self::factor('Flexibility', 88, 'No prepayment charge for individual borrowers on floating rate.'),
                    'suitability' => self::factor('Suitability', 72, 'Only suitable if you own property and accept the collateral risk.'),
                    'transparency' => self::factor('Transparency', 84, 'Charges are clear, though valuation costs are billed separately.'),
                ],
            ],
        ];
    }
}
