<?php

namespace App\Domain\ApplicationDocuments;

use App\Domain\Vault\DocumentType;
use App\Models\LoanApplication;

/**
 * Which documents an application asks for.
 *
 * Derived from the application's own snapshotted category, deliberately, and
 * that choice is what gives Module 29's historical integrity for free: an
 * application records `product_category` when it is created, so a catalogue
 * change later cannot alter what an old application asks for. There is no
 * live product lookup here and no requirements table to migrate.
 *
 * These sets are FynnEdge's own. No provider has published a document
 * checklist, because no provider is connected — so these describe what a
 * lending application of each kind ordinarily involves, and they are stated
 * as FynnEdge's expectation rather than as any lender's requirement. When a
 * real provider publishes its own list, this class is where that arrives,
 * and nothing above it changes.
 */
final class RequirementCatalogue
{
    /**
     * The requirements for an application, in the order a customer meets
     * them.
     *
     * @return list<DocumentRequirement>
     */
    public static function forApplication(LoanApplication $application): array
    {
        return self::forCategory($application->product_category);
    }

    /** @return list<DocumentRequirement> */
    public static function forCategory(string $category): array
    {
        $identity = new DocumentRequirement(
            code: 'identity',
            title: 'Identity document',
            description: 'Something that shows who you are — a PAN card, '
                .'passport or driving licence.',
            documentType: DocumentType::Identity,
        );

        $income = new DocumentRequirement(
            code: 'income',
            title: 'Income proof',
            description: 'A recent salary slip, or your latest income tax '
                .'return if you are self-employed.',
            documentType: DocumentType::Income,
        );

        $bank = new DocumentRequirement(
            code: 'bank',
            title: 'Bank statement',
            description: 'A recent statement for the account your salary or '
                .'income is paid into.',
            documentType: DocumentType::Bank,
        );

        $property = new DocumentRequirement(
            code: 'property',
            title: 'Property document',
            description: 'Paperwork for the property involved — the sale '
                .'agreement or title document.',
            documentType: DocumentType::Property,
        );

        /*
         * Optional everywhere it appears.
         *
         * An existing loan statement helps an application read accurately
         * when a customer already has borrowing, and its absence proves
         * nothing — so it is offered and never blocks.
         */
        $existingLoan = new DocumentRequirement(
            code: 'existing_loan',
            title: 'Existing loan statement',
            description: 'If you are already repaying a loan, a recent '
                .'statement for it. Not required.',
            documentType: DocumentType::Loan,
            required: false,
        );

        return match ($category) {
            'home', 'lap' => [$identity, $income, $bank, $property, $existingLoan],
            'personal', 'business' => [$identity, $income, $bank, $existingLoan],

            // A category FynnEdge does not have a list for asks for the two
            // things every lending application involves, and nothing it
            // cannot justify.
            default => [$identity, $income, $existingLoan],
        };
    }

    /** The requirement with this code, or null when there is no such slot. */
    public static function find(
        LoanApplication $application,
        string $code,
    ): ?DocumentRequirement {
        foreach (self::forApplication($application) as $requirement) {
            if ($requirement->code === $code) {
                return $requirement;
            }
        }

        return null;
    }

    private function __construct() {}
}
