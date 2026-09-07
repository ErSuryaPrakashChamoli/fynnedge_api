<?php

namespace Tests\Feature\Notifications;

use App\Domain\Notifications\DeepLink;
use App\Domain\Notifications\NotificationDecision;
use App\Domain\Notifications\NotificationPolicy;
use App\Models\Document;
use App\Models\Notification;
use App\Models\User;
use App\Services\Notifications\NotificationRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Whose notifications, and what reading one does.
 */
class NotificationCentreTest extends TestCase
{
    use RefreshDatabase;

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();

        return $user;
    }

    private function as(User $user): void
    {
        Sanctum::actingAs($user);
    }

    /** A real notification, built through the policy like every other one. */
    private function give(User $user, string $key = 'test'): Notification
    {
        $decision = (new NotificationPolicy)->documentReady(
            $this->documentFor($user)
        );

        $recorded = app(NotificationRecorder::class)->record(
            $user,
            NotificationDecision::of(
                type: $decision->type,
                title: $decision->title,
                body: $decision->body,
                idempotencyKey: $key,
                deepLink: $decision->deepLink,
            ),
        );

        return $recorded->notification;
    }

    private function documentFor(User $user): Document
    {
        return $user->documents()->create([
            'type' => 'income',
            'display_name' => 'Payslip',
            'original_filename' => 'payslip.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 100,
            'storage_disk' => 'documents',
            'storage_key' => 'documents/u1/'.uniqid().'.pdf',
            'status' => 'stored',
        ]);
    }

    // --- Ownership ---------------------------------------------------------

    public function test_a_customer_sees_their_own_notifications(): void
    {
        $user = $this->customer();
        $this->give($user);
        $this->as($user);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.unread_count', 1);
    }

    public function test_a_customer_never_sees_another_customers_notifications(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $this->give($theirs);

        $this->as($mine);

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(0, 'data')
            ->assertJsonPath('meta.unread_count', 0);
    }

    public function test_another_customers_notification_is_not_found(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $notification = $this->give($theirs);

        $this->as($mine);

        // The same 404 as an id that never existed. "Not yours" and "no
        // such thing" must be indistinguishable.
        $this->getJson("/api/v1/notifications/{$notification->id}")->assertStatus(404);
        $this->getJson('/api/v1/notifications/999999')->assertStatus(404);
    }

    public function test_reading_another_customers_notification_is_refused_and_changes_nothing(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $notification = $this->give($theirs);

        $this->as($mine);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertStatus(404);

        $this->assertNull($notification->fresh()->read_at);
    }

    public function test_read_all_cannot_reach_another_customer(): void
    {
        $mine = $this->customer();
        $theirs = $this->customer();
        $mineNotification = $this->give($mine);
        $theirsNotification = $this->give($theirs);

        $this->as($mine);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 1);

        $this->assertNotNull($mineNotification->fresh()->read_at);
        $this->assertNull($theirsNotification->fresh()->read_at);
    }

    // --- Read state --------------------------------------------------------

    public function test_a_notification_can_be_marked_read(): void
    {
        $user = $this->customer();
        $notification = $this->give($user);
        $this->as($user);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_reading_twice_does_not_move_when_they_first_read_it(): void
    {
        $user = $this->customer();
        $notification = $this->give($user);
        $this->as($user);

        $this->postJson("/api/v1/notifications/{$notification->id}/read")->assertOk();
        $first = $notification->fresh()->read_at;

        $this->travel(5)->minutes();

        $this->postJson("/api/v1/notifications/{$notification->id}/read")
            ->assertOk()
            ->assertJsonPath('data.read', true);

        $this->assertEquals($first, $notification->fresh()->read_at);
    }

    public function test_read_all_is_safe_to_repeat(): void
    {
        $user = $this->customer();
        $this->give($user, 'a');
        $this->give($user, 'b');
        $this->as($user);

        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 2)
            ->assertJsonPath('data.unread_count', 0);

        // Nothing left to mark, and that is not an error.
        $this->postJson('/api/v1/notifications/read-all')
            ->assertOk()
            ->assertJsonPath('data.marked', 0)
            ->assertJsonPath('data.unread_count', 0);
    }

    // --- Pagination --------------------------------------------------------

    public function test_history_is_paged_newest_first(): void
    {
        $user = $this->customer();

        for ($i = 1; $i <= 5; $i++) {
            $this->give($user, "n{$i}");
        }

        $this->as($user);

        $first = $this->getJson('/api/v1/notifications?limit=2')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.has_more', true);

        $ids = array_column($first->json('data'), 'id');
        $this->assertSame(['5', '4'], $ids);

        $cursor = $first->json('meta.next_cursor');
        $this->assertSame(4, $cursor);

        $second = $this->getJson("/api/v1/notifications?limit=2&before={$cursor}")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertSame(['3', '2'], array_column($second->json('data'), 'id'));
    }

    public function test_the_last_page_says_it_is_the_last(): void
    {
        $user = $this->customer();
        $this->give($user, 'only');
        $this->as($user);

        $this->getJson('/api/v1/notifications?limit=5')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('meta.has_more', false)
            ->assertJsonPath('meta.next_cursor', null);
    }

    public function test_a_page_cannot_be_made_unbounded(): void
    {
        $user = $this->customer();
        $this->as($user);

        // A client asking for the whole table is refused rather than
        // quietly served it.
        $this->getJson('/api/v1/notifications?limit=100000')->assertStatus(422);
        $this->getJson('/api/v1/notifications?limit=0')->assertStatus(422);
    }

    // --- The response itself -----------------------------------------------

    public function test_the_response_carries_no_internal_bookkeeping(): void
    {
        $user = $this->customer();
        $this->give($user, 'application:7:provider:acme:evt_1');
        $this->as($user);

        $body = $this->getJson('/api/v1/notifications')->getContent();

        // The idempotency key says which internal event produced this and
        // describes the shape of the system. It is not a customer's
        // business.
        $this->assertStringNotContainsString('idempotency', $body);
        $this->assertStringNotContainsString('evt_1', $body);
    }

    public function test_every_deep_link_is_one_the_router_recognises(): void
    {
        $user = $this->customer();
        $this->give($user);
        $this->as($user);

        foreach ($this->getJson('/api/v1/notifications')->json('data') as $item) {
            $this->assertTrue(
                DeepLink::isValid($item['deep_link']),
                "a notification points at [{$item['deep_link']}]",
            );
        }
    }

    public function test_the_centre_requires_a_session(): void
    {
        $this->getJson('/api/v1/notifications')->assertStatus(401);
        $this->getJson('/api/v1/notifications/unread-count')->assertStatus(401);
        $this->postJson('/api/v1/notifications/read-all')->assertStatus(401);
        $this->postJson('/api/v1/notifications/1/read')->assertStatus(401);
        $this->getJson('/api/v1/notifications/preferences')->assertStatus(401);
        $this->postJson('/api/v1/notifications/devices')->assertStatus(401);
    }
}
