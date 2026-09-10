<?php

namespace Tests\Feature\Decisions;

use App\Domain\Decisions\DecisionSnapshot;
use App\Domain\Decisions\DecisionSource;
use App\Domain\Decisions\DecisionType;
use PHPUnit\Framework\TestCase;

/**
 * What FynnEdge is willing to call a decision, and where it keeps each one.
 *
 * The catalogue is small on purpose, and the reasons are worth pinning
 * because the pressure will always be to grow it. Every additional type
 * makes a history look richer, and most of the candidates would be records
 * of somebody opening a screen.
 */
class DecisionCatalogueTest extends TestCase
{
    public function test_only_real_customer_actions_are_written_here(): void
    {
        /*
         * Two written, two read from elsewhere.
         *
         * A simulation and a comparison reach the server as actions the
         * customer took. Applications already carry an immutable snapshot
         * of what was shown, so decision history reads `loan_applications`
         * rather than keeping a second copy that could disagree with it.
         */
        $this->assertSame(
            ['simulation', 'comparison'],
            array_values(array_map(
                fn (DecisionType $t) => $t->value,
                array_filter(DecisionType::cases(), fn (DecisionType $t) => $t->isRecorded()),
            )),
        );

        $this->assertSame(
            ['application_started', 'application_submitted'],
            array_values(array_map(
                fn (DecisionType $t) => $t->value,
                array_filter(DecisionType::cases(), fn (DecisionType $t) => ! $t->isRecorded()),
            )),
        );
    }

    public function test_there_is_no_type_for_opening_a_screen(): void
    {
        /*
         * The types that would exist if a screen view were a decision.
         *
         * FynnTrust and FynnMirror have no API endpoint at all, so
         * recording them would mean inventing a tracking endpoint to build
         * a history of what somebody looked at. Opening a product is not
         * wanting it, and a product that says otherwise is telling the
         * customer something untrue about themselves.
         */
        $ids = DecisionType::ids();

        foreach ([
            'product_detail_review',
            'fynntrust_review',
            'fynnmirror_review',
            'product_viewed',
            'screen_viewed',
        ] as $view) {
            $this->assertNotContains($view, $ids, "[{$view}] is a page view, not a decision");
        }
    }

    public function test_no_type_claims_the_customer_wanted_anything(): void
    {
        foreach (DecisionType::cases() as $type) {
            $whole = strtolower($type->label().' '.$type->description());

            foreach ([
                'wanted', 'prefer', 'chose', 'intended', 'decided to',
                'recommended', 'best', 'approved',
            ] as $inference) {
                $this->assertStringNotContainsString(
                    $inference,
                    $whole,
                    "[{$type->value}] claims the customer {$inference}",
                );
            }
        }
    }

    public function test_every_provenance_is_a_real_origin(): void
    {
        // No `estimated`, no `predicted`, no `inferred`. FynnEdge either
        // holds a figure, was given one, or worked one out.
        foreach (DecisionSource::cases() as $source) {
            $this->assertNotContains($source->value, [
                'estimated', 'predicted', 'inferred', 'guess', 'ai',
            ]);
            $this->assertNotEmpty($source->label());
        }
    }

    public function test_the_allowlist_holds_no_identity_or_document_field(): void
    {
        foreach (DecisionSnapshot::ALLOWED as $field) {
            foreach ([
                'pan', 'aadhaar', 'document', 'storage', 'token', 'payload',
                'digest', 'account', 'kyc', 'credit_report',
            ] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $field,
                    "[{$field}] is not a figure a decision record should keep",
                );
            }
        }
    }
}
