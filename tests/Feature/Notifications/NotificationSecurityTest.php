<?php

namespace Tests\Feature\Notifications;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Notifications\DeliveryAttempt;
use App\Domain\Notifications\DeliveryStatus;
use App\Domain\Notifications\NotificationDecision;
use App\Domain\Notifications\NotificationDeliveryProvider;
use App\Domain\Notifications\NotificationType;
use App\Domain\Notifications\UnavailableNotificationDeliveryProvider;
use App\Domain\Scan\Fixture\FixtureScenario;
use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\UnavailableMalwareScanner;
use App\Jobs\DeliverNotification;
use App\Models\Document;
use App\Models\Notification;
use App\Models\NotificationDelivery;
use App\Models\NotificationDevice;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\DocumentVaultService;
use App\Services\Notifications\NotificationRecorder;
use App\Services\Notifications\PushDeliveryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * What a notification must not carry, and what a push must not claim.
 *
 * NO PUSH PROVIDER IS CONNECTED. That is the state most of this file is
 * about: an absent provider must produce an honest "nothing was attempted",
 * never a success, and never the disappearance of the notification itself.
 */
class NotificationSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        Sanctum::actingAs($user);

        return $user;
    }

    private function give(User $user, NotificationType $type = NotificationType::ApplicationApproved): Notification
    {
        return app(NotificationRecorder::class)->record($user, NotificationDecision::of(
            type: $type,
            title: 'The provider approved your application',
            body: 'The provider has approved your application. Open it to '
                .'see what they said and what happens next.',
            idempotencyKey: 'k'.uniqid(),
        ))->notification;
    }

    // --- Nothing sensitive travels -----------------------------------------

    public function test_a_document_journey_leaves_nothing_sensitive_in_a_notification(): void
    {
        $user = $this->customer();
        config(['fynnedge.scan.driver' => 'fixture', 'fynnedge.kyc.driver' => 'fixture']);
        app(ConsentService::class)->grant($user, ConsentType::DocumentProcessing, 'privacy_screen');
        app(ConsentService::class)->grant($user, ConsentType::IdentityVerification, 'privacy_screen');
        $this->app->instance(MalwareScanner::class,
            new UnavailableMalwareScanner);

        config(['fynnedge.scan.fixture_scenario' => FixtureScenario::Good->value]);

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $documentId = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'income.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $this->postJson("/api/v1/documents/{$documentId}/scan")->assertOk();

        $body = $this->getJson('/api/v1/notifications')->assertOk()->getContent();

        $document = Document::findOrFail($documentId);

        foreach ([
            // Identity values the fixture returns and Module 25 discards.
            FixtureKycVerificationProvider::ID_NUMBER,
            'AAAAA0000A', '000000000000', 'aadhaar', 'Aadhaar',

            // Where the file lives, in either half of its shape.
            $document->storage_key, $document->storage_disk, 'storage_key',

            // Extraction, provider payloads and bookkeeping.
            'net_pay', 'grossPay', 'extraction', 'payload', 'idempotency',
            'provider_reference', 'file_digest',
        ] as $leak) {
            $this->assertStringNotContainsString(
                (string) $leak,
                $body,
                "the notification centre returned [{$leak}]",
            );
        }
    }

    public function test_a_notification_carries_no_authentication_material(): void
    {
        $user = $this->customer();
        $token = $user->createToken('probe')->plainTextToken;
        $this->give($user);

        $body = $this->getJson('/api/v1/notifications')->getContent();

        $this->assertStringNotContainsString($token, $body);
        $this->assertStringNotContainsString('Bearer', $body);
    }

    public function test_a_registered_device_token_never_comes_back(): void
    {
        $this->customer();

        $secret = 'device-token-value-abcdefghijklmnop';

        $created = $this->postJson('/api/v1/notifications/devices', [
            'token' => $secret,
            'platform' => 'android',
        ])->assertCreated();

        // The address of somebody's handset. It goes in and does not come
        // out — not in the response, not in a fragment.
        $this->assertStringNotContainsString($secret, $created->getContent());
        $this->assertStringNotContainsString(substr($secret, 0, 12), $created->getContent());

        // And it is hidden on the model, so an accidental toArray anywhere
        // cannot publish it either.
        $this->assertArrayNotHasKey('token', NotificationDevice::first()->toArray());
    }

    public function test_nothing_logs_a_device_token(): void
    {
        $this->customer();
        $secret = 'device-token-value-abcdefghijklmnop';

        $lines = [];
        Log::listen(function ($message) use (&$lines) {
            $lines[] = json_encode([$message->message, $message->context]);
        });

        $this->postJson('/api/v1/notifications/devices', [
            'token' => $secret,
            'platform' => 'ios',
        ])->assertCreated();

        $notification = $this->give($this->customer());
        (new DeliverNotification($notification->getKey()))->handle(app(PushDeliveryService::class));

        $this->assertStringNotContainsString($secret, implode("\n", $lines));
    }

    // --- Device ownership --------------------------------------------------

    public function test_registering_a_token_that_belongs_to_someone_else_takes_it_over(): void
    {
        $first = $this->customer();
        $token = 'shared-handset-token-abcdefghijkl';

        $this->postJson('/api/v1/notifications/devices', [
            'token' => $token, 'platform' => 'android',
        ])->assertCreated();

        $second = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => $token, 'platform' => 'android',
        ])->assertSuccessful();

        /*
         * A handset belongs to whoever is signed in on it. Leaving the
         * first registration active would deliver the first customer's
         * notifications to a phone the second is holding.
         */
        $this->assertSame(
            0,
            NotificationDevice::where('user_id', $first->getKey())->count(),
            'the previous owner still holds this handset\'s address',
        );
        $this->assertTrue(
            NotificationDevice::where('user_id', $second->getKey())->first()->active,
        );

        // And exactly one registration exists for the device, which is what
        // the unique index guarantees.
        $this->assertSame(1, NotificationDevice::where('token', $token)->count());
    }

    public function test_a_customer_cannot_deactivate_another_customers_device(): void
    {
        $first = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'first-handset-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $device = NotificationDevice::where('user_id', $first->getKey())->firstOrFail();

        $this->customer();

        $this->deleteJson("/api/v1/notifications/devices/{$device->id}")->assertStatus(404);

        $this->assertTrue($device->fresh()->active);
    }

    // --- Push, and what it may claim ---------------------------------------

    public function test_no_push_provider_is_connected(): void
    {
        // The report this module has to be able to make honestly.
        $this->assertSame('none', config('fynnedge.notifications.push.driver'));

        $provider = app(NotificationDeliveryProvider::class);

        $this->assertInstanceOf(UnavailableNotificationDeliveryProvider::class, $provider);
        $this->assertFalse($provider->isAvailable());
    }

    public function test_an_absent_provider_never_claims_delivery(): void
    {
        $user = $this->customer();
        $notification = $this->give($user);

        app(PushDeliveryService::class)->deliver($notification);

        $delivery = NotificationDelivery::firstOrFail();

        $this->assertSame(DeliveryStatus::Unavailable, $delivery->status());
        $this->assertFalse($delivery->status()->reachedAProvider());
        $this->assertFalse($delivery->is_simulated);
    }

    public function test_a_mock_provider_is_explicit_about_simulating(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $notification = $this->give($user);
        app(PushDeliveryService::class)->deliver($notification);

        $delivery = NotificationDelivery::firstOrFail();

        // Simulated is a different state from sent everywhere it is read.
        $this->assertSame(DeliveryStatus::Simulated, $delivery->status());
        $this->assertTrue($delivery->is_simulated);
        $this->assertFalse($delivery->status()->reachedAProvider());
    }

    public function test_a_simulated_provider_claiming_it_sent_is_not_believed(): void
    {
        /*
         * The one place a fixture could quietly become a claim that a real
         * customer's phone lit up. The provider says it is simulated, so
         * simulated is what is written whatever its attempt returned.
         */
        $this->app->bind(NotificationDeliveryProvider::class, fn () => new class implements NotificationDeliveryProvider
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function isSimulated(): bool
            {
                return true;
            }

            public function name(): string
            {
                return 'lying-fixture';
            }

            public function push($notification, $device): DeliveryAttempt
            {
                return DeliveryAttempt::sent();
            }
        });

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        app(PushDeliveryService::class)->deliver($this->give($user));

        $this->assertSame(DeliveryStatus::Simulated, NotificationDelivery::firstOrFail()->status());
    }

    public function test_a_failed_push_never_removes_the_notification(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $this->app->bind(NotificationDeliveryProvider::class, fn () => new class implements NotificationDeliveryProvider
        {
            public function isAvailable(): bool
            {
                return true;
            }

            public function isSimulated(): bool
            {
                return false;
            }

            public function name(): string
            {
                return 'exploding';
            }

            public function push($notification, $device): DeliveryAttempt
            {
                throw new \RuntimeException('the provider fell over');
            }
        });

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $notification = $this->give($user);
        app(PushDeliveryService::class)->deliver($notification);

        // NOTIFICATION CREATED ≠ PUSH DELIVERED. The customer opens the app
        // and it is there.
        $this->assertNotNull($notification->fresh());
        $this->assertSame(DeliveryStatus::Failed, NotificationDelivery::firstOrFail()->status());

        $this->getJson('/api/v1/notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_retrying_a_delivery_does_not_create_a_second_notification_or_a_second_delivery(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $notification = $this->give($user);
        $job = new DeliverNotification($notification->getKey());
        $service = app(PushDeliveryService::class);

        $job->handle($service);
        $job->handle($service);
        $job->handle($service);

        $this->assertSame(1, Notification::count());
        $this->assertSame(1, NotificationDelivery::count());
    }

    // --- Preferences -------------------------------------------------------

    public function test_turning_push_off_does_not_empty_the_notification_centre(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $this->putJson('/api/v1/notifications/preferences', ['push_enabled' => false])
            ->assertOk()
            ->assertJsonPath('data.push_enabled', false);

        $notification = $this->give($user);
        app(PushDeliveryService::class)->deliver($notification);

        // Nothing pushed...
        $this->assertSame(DeliveryStatus::Unavailable, NotificationDelivery::firstOrFail()->status());
        $this->assertSame('push_disabled_by_customer', NotificationDelivery::firstOrFail()->reason);

        // ...and the message is still there to be read.
        $this->getJson('/api/v1/notifications')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_an_optional_category_can_be_muted(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        $this->putJson('/api/v1/notifications/preferences', [
            'muted_categories' => ['financial_health'],
        ])->assertOk()->assertJsonPath('data.muted_categories', ['financial_health']);

        app(PushDeliveryService::class)->deliver(
            $this->give($user, NotificationType::FynnScoreChanged)
        );

        $this->assertSame('category_muted_by_customer', NotificationDelivery::firstOrFail()->reason);
    }

    public function test_a_transactional_category_cannot_be_muted_at_all(): void
    {
        $this->customer();

        // Refused rather than accepted and quietly ignored, so a client is
        // told the truth about what it asked for.
        $this->putJson('/api/v1/notifications/preferences', [
            'muted_categories' => ['application'],
        ])->assertStatus(422);

        $this->putJson('/api/v1/notifications/preferences', [
            'muted_categories' => ['document'],
        ])->assertStatus(422);

        $this->putJson('/api/v1/notifications/preferences', [
            'muted_categories' => ['system'],
        ])->assertStatus(422);
    }

    public function test_a_mute_cannot_reach_a_transactional_notification_even_if_one_is_stored(): void
    {
        config(['fynnedge.notifications.push.driver' => 'mock']);

        $user = $this->customer();
        $this->postJson('/api/v1/notifications/devices', [
            'token' => 'a-device-token-abcdefghijklmn', 'platform' => 'ios',
        ])->assertCreated();

        /*
         * The API refuses to store this, so it is written straight to the
         * row — the belt-and-braces check that the suppression rule itself
         * asks the type whether it is transactional, rather than trusting
         * that nothing invalid ever got into the column.
         */
        $user->notificationPreference()->create(['muted_categories' => ['application']]);

        app(PushDeliveryService::class)->deliver(
            $this->give($user, NotificationType::ActionRequired)
        );

        $delivery = NotificationDelivery::firstOrFail();

        $this->assertSame(DeliveryStatus::Simulated, $delivery->status());
        $this->assertNull($delivery->reason);
    }

    public function test_the_preferences_endpoint_states_what_is_actually_available(): void
    {
        $this->customer();

        $this->getJson('/api/v1/notifications/preferences')
            ->assertOk()
            ->assertJsonPath('data.push_available', false)
            ->assertJsonPath('data.marketing_available', false)
            ->assertJsonPath('data.mutable_categories', ['financial_health', 'goal']);
    }
}
