<?php

namespace Tests\Unit;

use App\Domain\Consent\ConsentType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PHP side of the consent catalogue fixture, and the rules that hold
 * whatever the catalogue grows into.
 */
class ConsentCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/consent_catalogue.json'),
            true,
        );
    }

    public function test_the_fixture_is_current(): void
    {
        $this->artisan('fynn:parity-fixture', ['--check' => true])
            ->assertSuccessful();
    }

    public function test_every_type_appears_in_the_fixture(): void
    {
        $this->assertSame(
            ConsentType::ids(),
            array_column($this->fixture()['types'], 'type'),
        );
    }

    public function test_an_available_type_always_has_a_version(): void
    {
        foreach (ConsentType::cases() as $type) {
            if ($type->isAvailable()) {
                $this->assertNotNull(
                    $type->currentVersion(),
                    "{$type->value} is offered without a version to record it under",
                );
                $this->assertNull($type->unavailableReason());

                continue;
            }

            // Nothing to version, because no wording exists for a capability
            // that does not exist.
            $this->assertNull($type->currentVersion());
            $this->assertNotNull(
                $type->unavailableReason(),
                "{$type->value} is withheld without telling the customer why",
            );
        }
    }

    public function test_a_required_type_is_always_available(): void
    {
        // A required consent that cannot be granted would lock the customer
        // out of the product with no way back in.
        foreach (ConsentType::cases() as $type) {
            if ($type->isRequired()) {
                $this->assertTrue($type->isAvailable(), $type->value);
            }
        }
    }

    public function test_no_available_type_describes_a_bureau_or_lender_action(): void
    {
        foreach (ConsentType::available() as $type) {
            $text = mb_strtolower($type->label().' '.$type->description());

            foreach (['credit report', 'credit check', 'bureau', 'cibil'] as $forbidden) {
                $this->assertStringNotContainsString(
                    $forbidden,
                    $text,
                    "{$type->value} is grantable and refers to '{$forbidden}'",
                );
            }
        }
    }

    public function test_the_bureau_and_lender_types_are_withheld(): void
    {
        foreach ([
            ConsentType::CreditBureauCheck,
            ConsentType::LenderDataSharing,
            ConsentType::AiTraining,
            ConsentType::MarketingMessages,
        ] as $type) {
            $this->assertFalse($type->isAvailable(), $type->value);
            $this->assertFalse($type->isRequired(), $type->value);
        }
    }

    public function test_the_missing_legal_copy_is_flagged(): void
    {
        $this->assertStringContainsString(
            'No privacy policy',
            $this->fixture()['_legal_note'],
        );
    }
}
