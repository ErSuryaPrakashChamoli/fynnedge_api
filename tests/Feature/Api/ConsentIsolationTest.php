<?php

namespace Tests\Feature\Api;

use App\Domain\Consent\ConsentStatus;
use App\Domain\Consent\ConsentType;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * One customer's consent position must not depend on another customer's.
 *
 * `Consent::scopeLatestPerType()` finds the newest decision per type with a
 * correlated subquery, and the correlation is the whole of it. Written with
 * an unaliased inner `from('consents')`, the inner name shadows the outer,
 * the correlation silently becomes `user_id = user_id`, and the subquery
 * returns the newest id per type across every customer in the table. A
 * customer's own grant then vanishes from their position as soon as anybody
 * else decides the same type later.
 *
 * Every other consent test uses a single customer, which is exactly why that
 * went unnoticed: with one row per type in the whole table, the broken query
 * and the correct one return the same thing. These tests exist to need two.
 */
class ConsentIsolationTest extends TestCase
{
    use RefreshDatabase;

    private function decide(User $user, ConsentType $type, ConsentStatus $status): void
    {
        $user->consents()->create([
            'type' => $type->value,
            'status' => $status->value,
            'version' => $type->currentVersion(),
            'source' => 'test',
            'decided_at' => now(),
        ]);
    }

    public function test_a_grant_survives_another_customer_deciding_the_same_type_later(): void
    {
        $type = ConsentType::cases()[0];

        $mine = User::create(['mobile' => '9000000001']);
        $theirs = User::create(['mobile' => '9000000002']);

        // Mine is decided first, so it carries the lower id. Theirs is the
        // newest row for this type in the whole table.
        $this->decide($mine, $type, ConsentStatus::Granted);
        $this->decide($theirs, $type, ConsentStatus::Granted);

        $current = app(ConsentService::class)->current($mine);

        $this->assertArrayHasKey(
            $type->value,
            $current,
            'A customer\'s own consent disappeared from their position because '
            .'another customer decided the same type more recently.',
        );

        $this->assertSame(
            $mine->getKey(),
            $current[$type->value]->user_id,
            'The consent returned for this customer belongs to somebody else.',
        );
    }

    public function test_each_customer_keeps_their_own_latest_decision_per_type(): void
    {
        $type = ConsentType::cases()[0];

        $granted = User::create(['mobile' => '9000000003']);
        $withdrawn = User::create(['mobile' => '9000000004']);

        $this->decide($granted, $type, ConsentStatus::Granted);

        // The other customer grants and then withdraws, so the newest row in
        // the table for this type is a withdrawal that is not theirs.
        $this->decide($withdrawn, $type, ConsentStatus::Granted);
        $this->decide($withdrawn, $type, ConsentStatus::Withdrawn);

        $service = app(ConsentService::class);

        $this->assertSame(
            ConsentStatus::Granted->value,
            $service->current($granted)[$type->value]->status,
            'A withdrawal belonging to another customer changed this one\'s position.',
        );

        $this->assertSame(
            ConsentStatus::Withdrawn->value,
            $service->current($withdrawn)[$type->value]->status,
            'The customer\'s own withdrawal is not reflected in their position.',
        );
    }
}
