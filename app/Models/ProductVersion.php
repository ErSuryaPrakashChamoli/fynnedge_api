<?php

namespace App\Models;

use App\Domain\Catalog\ProductSource;
use App\Domain\Catalog\ProductSourceType;
use App\Domain\Catalog\ProductVersion as VersionData;
use App\Domain\Money;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One version of a product's terms, as the catalogue stores it.
 *
 * A draft may be edited. A published version may not — the domain refuses,
 * and the correction path is to clone it into a new draft. `published_at`
 * makes that a fact about the row rather than something to be inferred.
 */
class ProductVersion extends Model
{
    protected $fillable = [
        'catalogue_product_id', 'published_at',
        'effective_from', 'effective_until',
        'interest_rate', 'processing_fee_percent', 'processing_fee_cap',
        'prepayment_charge',
        'min_amount', 'max_amount', 'min_tenure_months', 'max_tenure_months',
        'min_monthly_income', 'min_age', 'max_age_at_maturity',
        'min_credit_score',
        'fynn_trust', 'trust_breakdown',
        'lender_tag', 'suited_reasons', 'considerations', 'conditions',
        'unsupported_requirements',
        'source_type', 'source_reference', 'source_retrieved_at',
    ];

    protected function casts(): array
    {
        return [
            'published_at' => 'datetime',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'source_retrieved_at' => 'datetime',
            'trust_breakdown' => 'array',
            'suited_reasons' => 'array',
            'considerations' => 'array',
            'conditions' => 'array',
            'unsupported_requirements' => 'array',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(CatalogueProduct::class, 'catalogue_product_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /** The domain object the catalogue reasons about. */
    public function toData(): VersionData
    {
        return new VersionData(
            persistedId: (int) $this->getKey(),
            effectiveFrom: $this->effective_from,
            effectiveUntil: $this->effective_until,
            interestRate: (float) $this->interest_rate,
            processingFeePercent: (float) $this->processing_fee_percent,
            processingFeeCap: $this->processing_fee_cap === null
                ? null
                : Money::of($this->processing_fee_cap),
            prepaymentCharge: (string) $this->prepayment_charge,
            minAmount: Money::of($this->min_amount),
            maxAmount: Money::of($this->max_amount),
            minTenureMonths: (int) $this->min_tenure_months,
            maxTenureMonths: (int) $this->max_tenure_months,
            minMonthlyIncome: $this->min_monthly_income === null
                ? null
                : Money::of($this->min_monthly_income),
            minAge: $this->min_age === null ? null : (int) $this->min_age,
            maxAgeAtMaturity: $this->max_age_at_maturity === null
                ? null
                : (int) $this->max_age_at_maturity,
            minCreditScore: $this->min_credit_score === null
                ? null
                : (int) $this->min_credit_score,
            fynnTrust: (int) $this->fynn_trust,
            trustBreakdown: $this->trust_breakdown ?? [],
            lenderTag: (string) $this->lender_tag,
            suitedReasons: $this->suited_reasons ?? [],
            considerations: $this->considerations ?? [],
            conditions: $this->conditions ?? [],
            source: $this->toSource(),
        );
    }

    public function toSource(): ProductSource
    {
        $type = ProductSourceType::from($this->source_type);

        if ($type === ProductSourceType::Sample) {
            return ProductSource::sample();
        }

        return ProductSource::fromProvider(
            $type,
            (string) $this->source_reference,
            $this->source_retrieved_at,
        );
    }
}
