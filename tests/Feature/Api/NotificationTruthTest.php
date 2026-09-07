<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Support\SeedData;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * A customer nothing has happened to is sent nothing.
 *
 * This endpoint used to answer with four seeded items. Two of them made
 * claims nobody could stand behind: one quoted an EMI ratio and a
 * month-on-month change no engine had calculated, and one was a reply from
 * a named advisor who does not exist.
 *
 * Module 29 gave FynnEdge real notifications, which makes this test more
 * useful rather than obsolete: an empty list is now a statement that
 * nothing has happened to this customer, and the vocabulary below is what
 * FynnEdge must never say on its own behalf. A provider decision is
 * attributed to the provider or it is not sent.
 */
class NotificationTruthTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_a_customer_who_has_been_sent_nothing_is_sent_nothing(): void
    {
        $this->customer();

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertExactJson([
                'data' => [],
                'meta' => [
                    'unread_count' => 0,
                    'next_cursor' => null,
                    'has_more' => false,
                ],
            ]);

        // And the badge agrees, from its own query rather than from the
        // page above.
        $this->getJson('/api/v1/notifications/unread-count')
            ->assertOk()
            ->assertExactJson(['data' => ['unread_count' => 0]]);
    }

    public function test_no_notification_speaks_for_a_provider_or_an_advisor(): void
    {
        $this->customer();
        $body = $this->getJson('/api/v1/notifications')->getContent();

        foreach ([
            'replied', 'advisor', 'Priya', 'approved', 'Approved',
            'lender', 'verified', 'EMI burden', '%',
        ] as $claim) {
            $this->assertStringNotContainsString($claim, $body);
        }
    }

    public function test_notifications_require_a_session(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
    }

    public function test_the_seeded_development_data_is_gone_entirely(): void
    {
        // App\Support\SeedData held a fabricated profile, financial figures,
        // goals, insights and notifications. Every endpoint reads real
        // records now, so nothing should reference it.
        $this->assertFalse(
            class_exists(SeedData::class),
            'SeedData is back — Home, goals and notifications must read the '
            .'customer\'s own records.'
        );
    }
}
