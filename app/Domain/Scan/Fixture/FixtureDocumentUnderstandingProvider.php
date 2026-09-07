<?php

namespace App\Domain\Scan\Fixture;

use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\DocumentUnderstandingUnavailable;
use App\Models\Document;

/**
 * A fictional document-understanding provider.
 *
 * NO LIVE DOCUMENT PROVIDER IS CONNECTED TO FYNNEDGE. This exists to prove
 * the boundary works — that a provider's vocabulary is translated, that its
 * surplus data is discarded, and that every way it can fail produces an
 * honest state rather than an invented reading.
 *
 * Everything it returns is synthetic. The names are invented, the employer
 * does not exist, and there is no PAN, Aadhaar or account number anywhere in
 * it that belongs to anybody.
 *
 * Its responses are deliberately written in a provider's dialect —
 * `netPay`, `payCycle`, `employeeFullName`, `extractionScore` — so the
 * normalizer can be tested translating them rather than merely asserted to.
 */
class FixtureDocumentUnderstandingProvider implements DocumentUnderstandingProvider
{
    public const NAME = 'fixture';

    /** The synthetic figures, in rupees. */
    public const GROSS = 85000;

    public const NET = 75000;

    public function __construct(
        /**
         * Which scenario this instance plays. Chosen server-side by tests
         * and configuration only — see FixtureScenario for why no request
         * may select it.
         */
        private readonly FixtureScenario $scenario = FixtureScenario::Good,
    ) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function name(): ?string
    {
        return self::NAME;
    }

    public function isSample(): bool
    {
        return true;
    }

    public function supports(DocumentFamily $family): bool
    {
        return $family === DocumentFamily::Income;
    }

    public function process(
        Document $document,
        string $bytes,
        DocumentFamily $family,
    ): array {
        return match ($this->scenario) {
            FixtureScenario::ProviderUnavailable => throw new DocumentUnderstandingUnavailable(
                DocumentUnderstandingUnavailable::PROVIDER_ERROR,
                'the fixture provider was asked to fail',
            ),

            FixtureScenario::ProviderTimeout => throw new DocumentUnderstandingUnavailable(
                DocumentUnderstandingUnavailable::PROVIDER_TIMEOUT,
                'the fixture provider was asked to time out',
            ),

            FixtureScenario::UnreadableDocument => throw new DocumentUnderstandingUnavailable(
                DocumentUnderstandingUnavailable::UNREADABLE_DOCUMENT,
                'the fixture provider could not read the file',
            ),

            FixtureScenario::UnsupportedDocument => throw new DocumentUnderstandingUnavailable(
                DocumentUnderstandingUnavailable::UNSUPPORTED_DOCUMENT,
                'the fixture provider does not read this family',
            ),

            FixtureScenario::Unreadable => ['this' => 'is not a response shape'],

            default => $this->payslip(),
        };
    }

    /**
     * A salary slip as a provider might report one.
     *
     * The surplus is deliberate. A real provider hands back everything it
     * saw — the employee's address, their bank account, their PAN, every
     * deduction line — and FynnEdge needs none of it. It is here so the
     * normalizer can be watched dropping it.
     *
     * @return array<string, mixed>
     */
    private function payslip(): array
    {
        $fields = [
            'employerName' => [
                'text' => 'Northwind Synthetic Services Pvt Ltd',
                'extractionScore' => 0.96,
                'location' => ['pageNumber' => 1, 'section' => 'Header'],
            ],
            'employeeFullName' => [
                'text' => 'Sample Employee',
                'extractionScore' => 0.95,
                'location' => ['pageNumber' => 1, 'section' => 'Header'],
            ],
            'grossPay' => [
                'text' => '₹85,000.00',
                'extractionScore' => 0.97,
                'location' => [
                    'pageNumber' => 1,
                    'section' => 'Earnings',
                    'rect' => [0.10, 0.42, 0.46, 0.46],
                ],
            ],
            'netPay' => [
                'text' => '₹75,000.00',
                'extractionScore' => 0.98,
                'location' => [
                    'pageNumber' => 1,
                    'section' => 'Net Pay',
                    'rect' => [0.10, 0.70, 0.46, 0.74],
                ],
            ],
            'payCycle' => [
                'text' => '2026-08',
                'extractionScore' => 0.94,
                'location' => ['pageNumber' => 1, 'section' => 'Header'],
            ],
        ];

        $fields = match ($this->scenario) {
            // The provider was there, and unsure.
            FixtureScenario::LowConfidence => array_replace_recursive($fields, [
                'netPay' => ['extractionScore' => 0.41],
            ]),

            // A field the document simply does not carry.
            FixtureScenario::MissingField => array_diff_key(
                $fields,
                ['netPay' => null],
            ),

            // Two candidates, and the document does not say which is meant.
            FixtureScenario::ConflictingField => array_replace_recursive($fields, [
                'netPay' => [
                    'text' => '₹75,000.00',
                    'alternatives' => ['₹72,400.00'],
                ],
            ]),

            // A notation no contract defines.
            FixtureScenario::AmbiguousAmount => array_replace_recursive($fields, [
                'netPay' => ['text' => '75K'],
            ]),

            // A date that could be two different months.
            FixtureScenario::AmbiguousDate => array_replace_recursive($fields, [
                'payCycle' => ['text' => '01/02/26'],
            ]),

            default => $fields,
        };

        return [
            'documentClass' => $this->scenario === FixtureScenario::Misclassified
                ? 'bank_statement'
                : 'salary_slip',

            'modelVersion' => 'fixture-payslip-2026.09',
            'fields' => $fields,

            // Everything below is surplus. None of it is needed to answer
            // the one question this feature asks, and none of it is kept.
            'consumer' => [
                'fullName' => 'Sample Employee',
                'panNumber' => 'AAAAA0000A',
                'aadhaarNumber' => '0000 0000 0000',
                'dateOfBirth' => '1990-01-01',
                'address' => '1 Invented Street, Nowhere',
                'bankAccountNumber' => '00000000000',
                'ifsc' => 'SMPL0000000',
            ],
            'deductions' => [
                ['label' => 'Provident Fund', 'amount' => '₹6,000.00'],
                ['label' => 'Professional Tax', 'amount' => '₹200.00'],
                ['label' => 'Income Tax', 'amount' => '₹3,800.00'],
            ],
            'rawText' => 'NORTHWIND SYNTHETIC SERVICES PVT LTD ... '
                .'SAMPLE EMPLOYEE ... PAN AAAAA0000A ... NET PAY 75,000.00',
        ];
    }
}
