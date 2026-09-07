<?php

namespace App\Domain\Match;

use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Credit\CreditProfile;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Financial\FinancialSnapshot;
use App\Domain\Financial\LoanProjection;
use App\Domain\Money;
use App\Services\FinanceService;

/**
 * FynnMatch — which products appear to fit this customer's stated need.
 *
 * WHAT THIS IS NOT: underwriting, an approval engine, an eligibility ruling,
 * or a probability of approval. It checks a customer's request and figures
 * against the rules each product actually documents, and says what it found.
 * Final terms and approval are the lender's to decide, and every result says
 * so.
 *
 * Division of responsibility:
 *   FinancialEngine  what are the customer's numbers?
 *   FynnScoreEngine  what do those numbers indicate about financial health?
 *   FynnMatchEngine  which products fit this request and those numbers?
 * FynnMatch consumes the other two; it never recomputes their definitions and
 * a match category is never a FynnScore.
 *
 * RULES IMPLEMENTED — each from an attribute the catalogue actually carries:
 *   purpose          product category vs the customer's stated purpose
 *   amount           requested amount within [min_amount, max_amount]
 *   tenure           requested tenure within [min, max] months
 *   minimum income   where the product documents one
 *   age at maturity  where the product documents one, and we know their age
 *   affordability    projected EMI ratio against the existing 45% ceiling
 *
 * RULES DELIBERATELY NOT IMPLEMENTED, because neither the catalogue nor the
 * customer record holds the data: business vintage, GST returns, audited
 * financials, minimum turnover, property valuation and title, credit history
 * of any kind. Those criteria report themselves unavailable rather than
 * quietly passing.
 *
 * There is no numeric match score. FynnEdge defines no weights for these
 * criteria, so a ranking number would be a guess wearing arithmetic. The
 * category is derived from criterion outcomes alone.
 */
class FynnMatchEngine
{
    /**
     * The affordability ceiling. Defined by FinancialEngine and referenced
     * here, so there is exactly one of it in the product.
     */
    public const AFFORDABILITY_CEILING_PERCENT = FinancialEngine::AFFORDABILITY_CEILING_PERCENT;

    public const DISCLAIMER = 'This is how these products line up against the '
        .'information FynnEdge holds. It is not an approval, and not a '
        .'lender decision. Final terms and eligibility are assessed by the '
        .'provider.';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly FinanceService $finance,
        private readonly FinancialEngine $financial,
    ) {}

    /**
     * @return list<ProductMatch> ordered best fit first, deterministically.
     */
    public function match(
        MatchRequest $request,
        FinancialSnapshot $financials,
        ?int $customerAge = null,
        ?CreditProfile $credit = null,
    ): array {
        $matches = array_map(
            fn (LoanProduct $product) => $this->evaluate(
                $product,
                $request,
                $financials,
                $customerAge,
                $credit,
            ),
            $this->products->all(),
        );

        // Best category first, then the product's own FynnTrust, then id.
        // Fully deterministic: no ties are left to array order.
        usort($matches, function (ProductMatch $a, ProductMatch $b) {
            return [$a->category->rank(), -$a->product->fynnTrust, $a->product->id]
                <=> [$b->category->rank(), -$b->product->fynnTrust, $b->product->id];
        });

        return $matches;
    }

    public function evaluate(
        LoanProduct $product,
        MatchRequest $request,
        FinancialSnapshot $financials,
        ?int $customerAge = null,
        ?CreditProfile $credit = null,
    ): ProductMatch {
        $criteria = [];

        $criteria[] = $this->purposeCriterion($product, $request);
        $criteria[] = $this->amountCriterion($product, $request);

        $tenureCriterion = $this->tenureCriterion($product, $request);
        $criteria[] = $tenureCriterion;

        $criteria[] = $this->incomeCriterion($product, $financials);
        $criteria[] = $this->ageCriterion($product, $request, $customerAge);

        // Only where the product actually publishes a minimum. A product
        // that says nothing about credit gets no credit criterion at all —
        // not an unavailable one — so it matches exactly as it did before
        // Module 23 and needs no bureau check to do so.
        if ($product->minCreditScore !== null) {
            $criteria[] = $this->creditCriterion($product, $credit);
        }

        // Price the product at what it can actually offer. The amount is never
        // changed — an out-of-range amount is a failed criterion, not a quiet
        // substitution — but the tenure is clamped so the EMI shown is one the
        // product could really give.
        $pricedTenure = $product->clampTenure($request->tenureMonths);
        $pricedAmount = $request->amount;

        $pricing = $this->finance->summary(
            (float) $pricedAmount->toDecimalString(),
            $product->interestRate,
            $pricedTenure,
        );
        $emi = Money::of($pricing['emi']);

        // One projection, used by the affordability criterion, by the match
        // payload and by every screen downstream.
        $projection = $this->financial->project($financials, $emi);

        $criteria[] = $this->affordabilityCriterion($projection);

        return new ProductMatch(
            product: $product,
            category: $this->categorise($criteria),
            criteria: $criteria,
            // Current position and position-with-this-loan, from the one
            // engine that owns both.
            projection: $projection,
            pricedAmount: $pricedAmount,
            pricedTenureMonths: $pricedTenure,
            emi: $emi,
            totalInterest: Money::of($pricing['total_interest']),
            totalPayable: Money::of($pricing['total_payable']),
            processingFee: $product->processingFeeFor($pricedAmount),
            tenureWasAdjusted: $pricedTenure !== $request->tenureMonths,
        );
    }

    /**
     * A documented rule failing is decisive. Otherwise a caution asks the
     * customer to look, and an unchecked criterion means we claim less.
     *
     * @param  list<MatchCriterion>  $criteria
     */
    private function categorise(array $criteria): MatchCategory
    {
        $statuses = array_map(fn (MatchCriterion $c) => $c->status, $criteria);

        if (in_array(CriterionStatus::Failed, $statuses, true)) {
            return MatchCategory::NotAMatch;
        }
        if (in_array(CriterionStatus::Caution, $statuses, true)) {
            return MatchCategory::Review;
        }
        if (in_array(CriterionStatus::Unavailable, $statuses, true)) {
            return MatchCategory::Good;
        }

        return MatchCategory::Strong;
    }

    /**
     * A product's documented minimum credit score, against what FynnEdge
     * knows of the customer's.
     *
     * Four outcomes, and the third is the one that matters:
     *
     *   score meets the minimum          passed
     *   score is below it                failed → Not a Match
     *   no credit information            unavailable → Good Match
     *
     * Only reached when the product publishes a minimum. One that does not
     * gets no criterion here at all, so it matches exactly as it did before
     * Module 23 — introducing credit must not make every product depend on
     * a bureau pull.
     *
     * Unavailable never becomes passed. A customer with no bureau report has
     * not satisfied a minimum score; FynnEdge simply cannot say either way,
     * and saying so is the whole point of the state.
     *
     * Passing says the documented requirement is met. It does not say a
     * provider would lend, and nothing here may imply it.
     */
    private function creditCriterion(
        LoanProduct $p,
        ?CreditProfile $credit,
    ): MatchCriterion {
        if ($credit === null) {
            return MatchCriterion::unavailable(
                'credit_score',
                'Credit score',
                'This product asks for a credit score of at least '
                    .$p->minCreditScore.'. FynnEdge has no credit '
                    .'information for you, so it cannot tell you whether you '
                    .'meet that.',
                'credit_score',
            );
        }

        if ($credit->meets($p->minCreditScore)) {
            return MatchCriterion::passed(
                'credit_score',
                'Credit score',
                'Your bureau score of '.$credit->score.' is at or above the '
                    .$p->minCreditScore.' this product asks for.',
                'credit_score',
            );
        }

        return MatchCriterion::failed(
            'credit_score',
            'Credit score',
            'Your bureau score of '.$credit->score.' is below the '
                .$p->minCreditScore.' this product asks for.',
            'credit_score',
        );
    }

    private function purposeCriterion(LoanProduct $p, MatchRequest $r): MatchCriterion
    {
        if ($r->purpose === null) {
            return MatchCriterion::unavailable(
                'purpose',
                'Purpose',
                'You have not told us what the money is for.',
            );
        }

        $wanted = self::categoryForPurpose($r->purpose);

        if ($wanted === null) {
            return MatchCriterion::unavailable(
                'purpose',
                'Purpose',
                'No product category maps to that purpose yet.',
            );
        }

        return $wanted === $p->category
            ? MatchCriterion::passed(
                'purpose',
                'Purpose',
                'This is a '.self::categoryLabel($p->category).', which is what you asked for.',
                'category',
            )
            : MatchCriterion::failed(
                'purpose',
                'Purpose',
                'This is a '.self::categoryLabel($p->category).', not what you asked for.',
                'category',
            );
    }

    private function amountCriterion(LoanProduct $p, MatchRequest $r): MatchCriterion
    {
        if ($p->supportsAmount($r->amount)) {
            return MatchCriterion::passed(
                'amount',
                'Amount',
                'Your '.$this->format($r->amount).' sits inside this product\'s '
                    .$this->format($p->minAmount).' to '.$this->format($p->maxAmount).' range.',
                'requested_amount',
            );
        }

        $tooSmall = $r->amount->compareTo($p->minAmount) < 0;

        return MatchCriterion::failed(
            'amount',
            'Amount',
            $tooSmall
                ? 'This product starts at '.$this->format($p->minAmount)
                    .', above your '.$this->format($r->amount).' request.'
                : 'This product stops at '.$this->format($p->maxAmount)
                    .', below your '.$this->format($r->amount).' request.',
            'requested_amount',
        );
    }

    private function tenureCriterion(LoanProduct $p, MatchRequest $r): MatchCriterion
    {
        if ($p->supportsTenure($r->tenureMonths)) {
            return MatchCriterion::passed(
                'tenure',
                'Tenure',
                'Your '.$this->months($r->tenureMonths).' term is available on this product.',
                'requested_tenure',
            );
        }

        return MatchCriterion::caution(
            'tenure',
            'Tenure',
            'This product runs '.$this->months($p->minTenureMonths).' to '
                .$this->months($p->maxTenureMonths).', so the figures below use '
                .$this->months($p->clampTenure($r->tenureMonths))
                .' rather than the '.$this->months($r->tenureMonths).' you asked for.',
            'requested_tenure',
        );
    }

    private function incomeCriterion(LoanProduct $p, FinancialSnapshot $f): MatchCriterion
    {
        if ($p->minMonthlyIncome === null) {
            return MatchCriterion::unavailable(
                'minimum_income',
                'Minimum income',
                'This product does not publish a minimum income.',
            );
        }

        if (! $f->income->isPositive()) {
            return MatchCriterion::unavailable(
                'minimum_income',
                'Minimum income',
                'Add your monthly income and we can check this.',
            );
        }

        return $f->income->compareTo($p->minMonthlyIncome) >= 0
            ? MatchCriterion::passed(
                'minimum_income',
                'Minimum income',
                'Your income meets this product\'s '
                    .$this->format($p->minMonthlyIncome).' minimum.',
                'monthly_income',
            )
            : MatchCriterion::failed(
                'minimum_income',
                'Minimum income',
                'This product requires '.$this->format($p->minMonthlyIncome)
                    .' a month; yours is '.$this->format($f->income).'.',
                'monthly_income',
            );
    }

    private function ageCriterion(
        LoanProduct $p,
        MatchRequest $r,
        ?int $age,
    ): MatchCriterion {
        if ($p->minAge === null && $p->maxAgeAtMaturity === null) {
            return MatchCriterion::unavailable(
                'age',
                'Age',
                'This product does not publish an age requirement.',
            );
        }

        if ($age === null) {
            return MatchCriterion::unavailable(
                'age',
                'Age',
                'Add your age and we can check this.',
            );
        }

        if ($p->minAge !== null && $age < $p->minAge) {
            return MatchCriterion::failed(
                'age',
                'Age',
                'This product starts at '.$p->minAge.'.',
                'age',
            );
        }

        $tenure = $p->clampTenure($r->tenureMonths);
        $ageAtMaturity = $age + (int) ceil($tenure / 12);

        if ($p->maxAgeAtMaturity !== null && $ageAtMaturity > $p->maxAgeAtMaturity) {
            return MatchCriterion::failed(
                'age',
                'Age',
                'You would be '.$ageAtMaturity.' when this loan ends; the '
                    .'product\'s limit is '.$p->maxAgeAtMaturity.'.',
                'age',
            );
        }

        return MatchCriterion::passed(
            'age',
            'Age',
            'You are inside this product\'s age requirement.',
            'age',
        );
    }

    /**
     * Beyond the ceiling is a warning, not a rejection: no product in the
     * catalogue documents an EMI-ratio rule, so treating it as a blocker
     * would be FynnEdge inventing a lender's policy.
     */
    private function affordabilityCriterion(LoanProjection $projection): MatchCriterion
    {
        if (! $projection->isMeasurable) {
            return MatchCriterion::unavailable(
                'affordability',
                'Affordability',
                'Add your monthly income and we can work out what this would '
                    .'cost you each month.',
            );
        }

        $projected = (float) $projection->projectedEmiRatio->toJsonNumber();

        if (! $projection->withinCeiling) {
            return MatchCriterion::caution(
                'affordability',
                'Affordability',
                'With your existing EMIs this would take '
                    .$this->percent($projected).'% of your income, past the '
                    .$this->percent(self::AFFORDABILITY_CEILING_PERCENT)
                    .'% FynnEdge uses as its affordability reference. '
                    .'That is FynnEdge\'s reference, not a lender rule.',
                'emi_ratio',
            );
        }

        return MatchCriterion::passed(
            'affordability',
            'Affordability',
            'With your existing EMIs this would take '
                .$this->percent($projected).'% of your income, inside the '
                .$this->percent(self::AFFORDABILITY_CEILING_PERCENT)
                .'% FynnEdge uses as its affordability reference.',
            'emi_ratio',
        );
    }

    /**
     * A purpose the given category serves.
     *
     * The inverse of categoryForPurpose, for the case where the customer has
     * already picked the product: evaluating it against a purpose it cannot
     * serve would report a mismatch they did not make.
     */
    public static function purposeForCategory(string $category): ?string
    {
        return match ($category) {
            'business' => 'business',
            'home' => 'home',
            'lap' => 'property',
            'personal' => 'personal',
            default => null,
        };
    }

    /** Which product category serves a stated purpose. */
    public static function categoryForPurpose(string $purpose): ?string
    {
        return match ($purpose) {
            'business' => 'business',
            'home' => 'home',
            'property' => 'lap',
            'personal', 'education', 'medical', 'car', 'debt', 'other' => 'personal',
            default => null,
        };
    }

    private static function categoryLabel(string $category): string
    {
        return match ($category) {
            'personal' => 'personal loan',
            'business' => 'business loan',
            'home' => 'home loan',
            'lap' => 'loan against property',
            default => $category,
        };
    }

    private function months(int $months): string
    {
        if ($months < 12) {
            return $months.' month'.($months === 1 ? '' : 's');
        }
        $years = intdiv($months, 12);
        $rest = $months % 12;

        return $rest === 0
            ? $years.' year'.($years === 1 ? '' : 's')
            : $years.'y '.$rest.'m';
    }

    private function percent(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    /** Indian digit grouping, defined by Money for the whole product. */
    private function format(Money $money): string
    {
        return $money->toIndianString();
    }
}
