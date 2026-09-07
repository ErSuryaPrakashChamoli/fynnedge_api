<?php

namespace App\Domain\Kyc\Fixture;

use App\Domain\Kyc\KycVerificationProvider;
use App\Domain\Kyc\VerificationType;
use App\Domain\Kyc\VerificationUnavailable;
use App\Models\Document;

/**
 * A fictional identity verification service.
 *
 * NO LIVE KYC PROVIDER IS CONNECTED TO FYNNEDGE. This exists to prove the
 * boundary works — that a provider's vocabulary is translated, that its
 * surplus identity data is discarded, that a document finding and an
 * identity finding stay apart, and that every way it can fail produces an
 * honest state rather than an invented verification.
 *
 * Everything it returns is synthetic. The person does not exist, the PAN is
 * a structurally-shaped placeholder that belongs to nobody, and the Aadhaar
 * number is all zeros. None of it is a real identifier.
 *
 * Its responses are written in a provider's dialect — `checkOutcome`,
 * `docAuthenticity`, `holderName`, `idNumber` — so the normalizer can be
 * tested translating them rather than merely asserted to.
 */
class FixtureKycVerificationProvider implements KycVerificationProvider
{
    public const NAME = 'fixture';

    /** The synthetic identity. Nobody's. */
    public const HOLDER_NAME = 'Sample Customer';

    public const MISMATCHED_NAME = 'Sample Customer Verma';

    public const ID_NUMBER = 'AAAAA0000A';

    public function __construct(
        private readonly FixtureScenario $scenario = FixtureScenario::Verified,
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

    public function supports(VerificationType $type): bool
    {
        return $type === VerificationType::IdentityDocument;
    }

    public function verify(
        Document $document,
        string $bytes,
        VerificationType $type,
    ): array {
        return match ($this->scenario) {
            FixtureScenario::ProviderUnavailable => throw new VerificationUnavailable(
                VerificationUnavailable::PROVIDER_ERROR,
                'the fixture provider was asked to fail',
            ),

            FixtureScenario::ProviderTimeout => throw new VerificationUnavailable(
                VerificationUnavailable::PROVIDER_TIMEOUT,
                'the fixture provider was asked to time out',
            ),

            FixtureScenario::UnreadableDocument => throw new VerificationUnavailable(
                VerificationUnavailable::UNREADABLE_DOCUMENT,
                'the fixture provider could not read the file',
            ),

            FixtureScenario::UnsupportedDocument => throw new VerificationUnavailable(
                VerificationUnavailable::UNSUPPORTED_DOCUMENT,
                'the fixture provider does not check this document kind',
            ),

            FixtureScenario::Unreadable => ['this' => 'is not a response shape'],

            default => $this->result(),
        };
    }

    /**
     * A verification result as a provider might report one.
     *
     * The surplus is deliberate. A real identity-verification response hands
     * back everything the document carried — the full number, a date of
     * birth, an address, a parent's name, a photograph — and FynnEdge needs
     * almost none of it. It is here so the normalizer can be watched
     * dropping it.
     *
     * @return array<string, mixed>
     */
    private function result(): array
    {
        $document = match ($this->scenario) {
            FixtureScenario::DocumentNotVerified => 'FAIL',
            FixtureScenario::DocumentTampered => 'TAMPER_SUSPECTED',
            FixtureScenario::Ambiguous => 'INDETERMINATE',
            default => 'PASS',
        };

        $holder = match ($this->scenario) {
            FixtureScenario::NameMismatch => self::MISMATCHED_NAME,
            FixtureScenario::NoIdentityReturned => null,
            default => self::HOLDER_NAME,
        };

        return [
            'checkOutcome' => $document,
            'docAuthenticity' => [
                'result' => $document,
                'signals' => $this->scenario === FixtureScenario::DocumentTampered
                    ? ['LAYOUT_MISMATCH', 'FONT_ANOMALY']
                    : [],
            ],
            'referenceId' => 'FIXTURE-KYC-0000-0000',
            'engineVersion' => 'fixture-identity-2026.09',

            'holder' => [
                'holderName' => $holder,

                // Surplus from here down. None of it has a purpose in
                // FynnEdge, and none of it is kept — see
                // IdentityFieldPurpose::DISCARDED.
                'idNumber' => self::ID_NUMBER,
                'aadhaarNumber' => '0000 0000 0000',
                'dateOfBirth' => '1990-01-01',
                'gender' => 'X',
                'fatherName' => 'Sample Parent',
                'address' => '1 Invented Street, Nowhere, 000000',
                'phone' => '0000000000',
                'photograph' => 'data:image/png;base64,SYNTHETICPLACEHOLDER',
            ],

            'rawText' => 'INCOME TAX DEPARTMENT ... SAMPLE CUSTOMER ... '
                .'AAAAA0000A ... 01/01/1990',
        ];
    }
}
