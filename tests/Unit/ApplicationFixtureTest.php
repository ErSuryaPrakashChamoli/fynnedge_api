<?php

namespace Tests\Unit;

use App\Domain\Application\ApplicationStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The PHP side of the application lifecycle fixture. The Flutter suite reads
 * the same file, so a change on either side that the other does not follow
 * breaks a build rather than shipping a quiet divergence.
 */
class ApplicationFixtureTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function fixture(): array
    {
        return json_decode(
            (string) file_get_contents(__DIR__.'/../fixtures/application_lifecycle.json'),
            true,
        );
    }

    public function test_the_fixture_is_current(): void
    {
        $this->artisan('fynn:parity-fixture', ['--check' => true])
            ->assertSuccessful();
    }

    public function test_it_covers_every_state_the_product_can_reach(): void
    {
        $fixture = $this->fixture();

        $this->assertSame(
            ['started', 'submitted', 'cancelled'],
            $fixture['implemented_states'],
        );
        $this->assertSame(
            $fixture['implemented_states'],
            array_column($fixture['cases'], 'name'),
        );
    }

    public function test_no_fixture_exists_for_a_state_nothing_can_produce(): void
    {
        $fixture = $this->fixture();

        foreach ($fixture['provider_states'] as $state) {
            $status = ApplicationStatus::from($state);

            $this->assertTrue($status->isProviderDecision());
            $this->assertNotContains($state, array_column($fixture['cases'], 'name'));
        }

        foreach ($fixture['implemented_states'] as $state) {
            $this->assertFalse(ApplicationStatus::from($state)->isProviderDecision());
        }
    }

    public function test_only_the_submitted_case_is_marked_simulated(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            $payload = $case['payload'];

            if ($case['name'] === 'submitted') {
                $this->assertTrue($payload['is_simulated']);
                $this->assertSame('mock', $payload['gateway']);
                $this->assertNull($payload['provider_reference']);

                continue;
            }

            // Nothing was handed to a gateway, so nothing claims one.
            $this->assertFalse($payload['is_simulated']);
            $this->assertNull($payload['gateway']);
        }
    }

    public function test_no_event_in_any_state_speaks_for_a_provider(): void
    {
        foreach ($this->fixture()['cases'] as $case) {
            foreach ($case['payload']['events'] as $event) {
                $this->assertNotSame(
                    'provider',
                    $event['source'],
                    "{$case['name']} contains an event attributed to a provider",
                );
            }
        }
    }
}
