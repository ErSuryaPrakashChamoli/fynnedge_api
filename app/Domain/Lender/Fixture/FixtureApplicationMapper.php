<?php

namespace App\Domain\Lender\Fixture;

use App\Domain\Lender\LenderApplicationMapper;
use App\Domain\Lender\LenderApplicationPayload;
use App\Domain\Lender\ProviderMappingMissing;
use App\Models\LoanApplication;

/**
 * FynnEdge's application into the fictional provider's vocabulary.
 *
 * The mapping is explicit and one-directional. FynnEdge calls a product
 * `sample_bank:personal_loan`; this provider calls it `PL-001`. Assuming the
 * identifiers match is how an application ends up submitted for a product
 * nobody chose, so an unmapped product raises rather than guessing.
 *
 * Every value comes from a named source. Nothing is here because it happened
 * to be reachable from the application object, and there is no
 * `$application->toArray()` anywhere in this class.
 */
class FixtureApplicationMapper implements LenderApplicationMapper
{
    /**
     * FynnEdge product identity → the provider's own code.
     *
     * A real mapper reads this from a provider contract or a mapping table.
     * The point is that a translation step exists at all.
     *
     * @var array<string, string>
     */
    private const PRODUCT_CODES = [
        'prod_pl_a' => 'PL-001',
        'prod_pl_b' => 'PL-002',
        'prod_bl_a' => 'BL-001',
        'prod_bl_b' => 'BL-002',
        'prod_hl_a' => 'HL-001',
        'prod_hl_b' => 'HL-002',

        // The persisted catalogue's sample product, so the boundary can be
        // exercised against a real ProductVersion as well as against the
        // six in-memory ones. Fictional, like the rest of this list.
        'sample_bank:term_loan' => 'TL-001',
    ];

    public function map(LoanApplication $application): LenderApplicationPayload
    {
        $code = self::PRODUCT_CODES[$application->product_id] ?? null;

        if ($code === null) {
            // Never a guessed code. Submitting under one would create an
            // application for a product the customer did not choose.
            throw new ProviderMappingMissing($application->product_id);
        }

        $user = $application->user;
        $customer = $user->customerProfile;
        $financial = $user->financialProfile;

        return LenderApplicationPayload::of(
            fynnEdgeReference: $application->reference,
            providerProductCode: $code,

            // Every key here is listed in SubmissionFieldMap::SENT with a
            // purpose, and a test enforces that the two agree.
            fields: [
                'fynnedge_reference' => $application->reference,
                'product_code' => $code,

                // The historical snapshot, always. A provider must see the
                // terms the customer applied under, not today's catalogue.
                'amount' => (float) $application->amount,
                'tenure_months' => (int) $application->tenure_months,
                'interest_rate' => (float) $application->interest_rate,
                'emi' => (float) $application->emi,

                'applicant_name' => $customer?->full_name,
                'applicant_mobile' => $user->mobile,
                'applicant_city' => $customer?->city,

                'monthly_income' => (float) ($financial?->monthly_income ?? 0),
                'employment_type' => $financial?->employment_type,
                'existing_emi' => (float) ($financial?->existing_emi ?? 0),
            ],
        );
    }
}
