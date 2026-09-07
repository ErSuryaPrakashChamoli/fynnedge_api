<?php

namespace App\Domain\Lender;

/**
 * Every field that leaves FynnEdge, and why.
 *
 * This class holds no logic. It exists so the question "what did you send
 * about me, and why?" has an answer in the codebase rather than only in a
 * report, and so that adding a field to the payload means writing down its
 * justification in the same commit.
 *
 * A test asserts that the canonical payload contains exactly the keys listed
 * in SENT and none of the ones in REFUSED. Adding a field without a purpose
 * fails that test.
 */
final class SubmissionFieldMap
{
    /**
     * What goes, with its source, purpose and sensitivity.
     *
     * @var array<string, array{source: string, purpose: string, sensitive: bool}>
     */
    public const SENT = [
        'fynnedge_reference' => [
            'source' => 'loan_applications.reference',
            'purpose' => 'So a provider and FynnEdge can talk about the same '
                .'application. FynnEdge\'s own reference, never replaced by '
                .'the provider\'s.',
            'sensitive' => false,
        ],
        'product_code' => [
            'source' => 'Mapped from the application\'s catalogue product',
            'purpose' => 'Which product is being applied for, in the '
                .'provider\'s own vocabulary.',
            'sensitive' => false,
        ],
        'amount' => [
            'source' => 'loan_applications.amount (historical snapshot)',
            'purpose' => 'What the customer applied for. From the snapshot, '
                .'never re-derived from today\'s catalogue.',
            'sensitive' => false,
        ],
        'tenure_months' => [
            'source' => 'loan_applications.tenure_months (snapshot)',
            'purpose' => 'The term applied for.',
            'sensitive' => false,
        ],
        'interest_rate' => [
            'source' => 'loan_applications.interest_rate (snapshot)',
            'purpose' => 'The rate the application was created under, so a '
                .'provider sees what the customer was shown.',
            'sensitive' => false,
        ],
        'emi' => [
            'source' => 'loan_applications.emi (snapshot)',
            'purpose' => 'The instalment the customer agreed to, as shown.',
            'sensitive' => false,
        ],
        'applicant_name' => [
            'source' => 'customer_profiles.full_name',
            'purpose' => 'A provider cannot accept an application from '
                .'nobody. The customer\'s own entered name.',
            'sensitive' => true,
        ],
        'applicant_mobile' => [
            'source' => 'users.mobile',
            'purpose' => 'The provider\'s means of contacting the applicant. '
                .'The number the customer signed in with.',
            'sensitive' => true,
        ],
        'applicant_city' => [
            'source' => 'customer_profiles.city',
            'purpose' => 'Serviceability. Providers do not lend everywhere.',
            'sensitive' => false,
        ],
        'monthly_income' => [
            'source' => 'financial_profiles.monthly_income',
            'purpose' => 'The one financial figure a lending application '
                .'cannot omit. The customer\'s own entered figure.',
            'sensitive' => true,
        ],
        'employment_type' => [
            'source' => 'financial_profiles.employment_type',
            'purpose' => 'Providers price and route by employment type.',
            'sensitive' => false,
        ],
        'existing_emi' => [
            'source' => 'financial_profiles.existing_emi',
            'purpose' => 'Existing obligations, which every provider asks '
                .'for and which the customer has already declared.',
            'sensitive' => true,
        ],
    ];

    /**
     * What FynnEdge holds and deliberately does not send, with the reason.
     *
     * @var array<string, string>
     */
    public const REFUSED = [
        'fynn_score' => 'FynnEdge\'s own view of financial health, built for '
            .'the customer to understand their position. It is not a lender '
            .'score, no provider asked for it, and sending it would invite '
            .'exactly the confusion Module 6 exists to prevent.',

        'fynn_trust' => 'FynnEdge\'s assessment of a product, not of a '
            .'customer. A provider has no use for FynnEdge\'s opinion of the '
            .'provider.',

        'twin_scenarios' => 'FynnTwin figures are simulations of choices the '
            .'customer has not made. Submitting a simulated income as a fact '
            .'would be submitting something untrue.',

        'credit_score' => 'A bureau score obtained under a consent that '
            .'covers FynnEdge asking, not FynnEdge redistributing. Module '
            .'23\'s boundary. Sending it needs a provider contract that '
            .'requires it and a consent that covers it; neither exists.',

        'scan_extraction' => 'Document extraction is evidence a customer '
            .'reviewed, not data a provider asked for. Raw OCR, page images '
            .'and confidence internals never leave FynnEdge.',

        'kyc_result' => 'Verification is a finding about a document. No '
            .'provider contract requires it, so it is not sent — and the '
            .'raw provider payload never would be.',

        'document_number' => 'PAN, Aadhaar and identity document numbers. '
            .'FynnEdge holds only a masked tail and sends nothing.',

        'documents' => 'Vault documents. Sending files needs a document '
            .'submission boundary and consent language that covers it; '
            .'Module 27 handles that. Nothing is sent because it happens to '
            .'be stored.',

        'savings' => 'Not required to submit an application. A provider that '
            .'needs it can ask for it under a contract that says so.',

        'monthly_expenses' => 'Same. FynnEdge uses it; a provider has not '
            .'asked for it.',

        'goals' => 'A customer\'s savings goals are none of a lender\'s '
            .'business.',

        'ai_conversations' => 'Never leaves FynnEdge, to anyone.',

        'internal_ids' => 'Database keys, storage keys, user ids. A provider '
            .'gets the FynnEdge application reference and nothing else that '
            .'identifies a row.',
    ];

    /** @return list<string> */
    public static function sentKeys(): array
    {
        return array_keys(self::SENT);
    }

    private function __construct() {}
}
