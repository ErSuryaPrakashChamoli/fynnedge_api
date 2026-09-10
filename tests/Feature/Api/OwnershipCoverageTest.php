<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\DocumentVaultService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Every route that names a thing, walked from the router itself.
 *
 * IdorMatrixTest checks the endpoints somebody remembered to add to it, and
 * it was written when FynnEdge had thirteen. It now has twenty-seven, and
 * the ones added since were covered by their own modules' tests — which is
 * fine until the module nobody writes a security test for.
 *
 * So this does not hold a list. It reads the route table, and every
 * authenticated route carrying a `{parameter}` must appear below with
 * either a probe or a written reason for needing none. A new endpoint fails
 * this test until somebody makes that choice deliberately, which is the
 * whole point: the failure mode being guarded against is not a wrong
 * decision but an absent one.
 */
class OwnershipCoverageTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Routes whose parameter is not a customer's record.
     *
     * Each carries the reason. A route added here without one is a route
     * somebody waved through.
     *
     * @var array<string, string>
     */
    private const NOT_CUSTOMER_SCOPED = [
        'api/v1/products/{id}' => 'The product catalogue is the same for '
            .'everybody. There is nothing here that belongs to a customer.',

        'api/v1/fynn-match/{product}' => 'A catalogue product, evaluated '
            .'against whoever is holding the token. The id names a product; '
            .'the figures come from the session.',

        'api/v1/consents/{type}/withdraw' => 'The parameter is a ConsentType '
            .'case, not an identifier. Whose consent is withdrawn comes from '
            .'the token.',

        'api/v1/provider-events/{provider}' => 'The provider ingress. Not a '
            .'customer route at all: it authenticates a provider, and Module '
            .'28 resolves whose application is affected from the provider\'s '
            .'own reference rather than from anything in the request.',
    ];

    /**
     * Routes that resolve a customer's own record, and how to probe one.
     *
     * `resource` names which of the owner's records to substitute for the
     * route's parameter. A route listed here is actually fired at as the
     * wrong customer, not merely declared covered.
     *
     * @var array<string, array{verb: string, resource: string, body?: array<string, mixed>}>
     */
    private const CUSTOMER_SCOPED = [
        'api/v1/applications/{application}' => ['verb' => 'GET', 'resource' => 'application'],
        'api/v1/applications/{application}/cancel' => ['verb' => 'POST', 'resource' => 'application'],
        'api/v1/applications/{application}/documents' => ['verb' => 'GET', 'resource' => 'application'],
        'api/v1/applications/{application}/submission' => ['verb' => 'GET', 'resource' => 'application'],
        'api/v1/applications/{application}/submit' => [
            'verb' => 'POST', 'resource' => 'application', 'body' => ['acknowledged' => true],
        ],
        'api/v1/applications/{application}/documents/{requirement}' => [
            'verb' => 'DELETE', 'resource' => 'application', 'parameter' => 'application',
        ],
        'api/v1/applications/{application}/documents/{requirement}/review' => [
            'verb' => 'POST', 'resource' => 'application', 'parameter' => 'application',
            'body' => ['decision' => 'accepted'],
        ],

        'api/v1/documents/{document}' => ['verb' => 'GET', 'resource' => 'document'],
        'api/v1/documents/{document}/download' => ['verb' => 'GET', 'resource' => 'document'],
        'api/v1/documents/{document}/scan' => ['verb' => 'GET', 'resource' => 'document'],
        'api/v1/documents/{document}/scan/review' => [
            'verb' => 'POST', 'resource' => 'document', 'body' => ['field_key' => 'net_pay', 'decision' => 'accepted'],
        ],
        'api/v1/documents/{document}/kyc' => ['verb' => 'GET', 'resource' => 'document'],
        'api/v1/documents/{document}/kyc/resolve' => [
            'verb' => 'POST', 'resource' => 'document', 'body' => ['field' => 'name', 'decision' => 'keep_profile'],
        ],

        'api/v1/goals/{goal}' => ['verb' => 'GET', 'resource' => 'goal'],

        'api/v1/decisions/{decision}' => ['verb' => 'GET', 'resource' => 'decision'],

        'api/v1/ai/conversations/{conversation}' => ['verb' => 'GET', 'resource' => 'conversation'],
        'api/v1/ai/conversations/{conversation}/messages' => [
            'verb' => 'POST', 'resource' => 'conversation', 'body' => ['text' => 'hello'],
        ],
        'api/v1/ai/memory/{memory}' => ['verb' => 'DELETE', 'resource' => 'memory'],

        'api/v1/notifications/{notification}' => ['verb' => 'GET', 'resource' => 'notification'],
        'api/v1/notifications/{notification}/read' => ['verb' => 'POST', 'resource' => 'notification'],
        'api/v1/notifications/devices/{device}' => ['verb' => 'DELETE', 'resource' => 'device'],

        'api/v1/support/cases/{case}' => ['verb' => 'GET', 'resource' => 'supportCase'],
        'api/v1/support/cases/{case}/close' => ['verb' => 'POST', 'resource' => 'supportCase'],
        'api/v1/support/cases/{case}/messages' => [
            'verb' => 'POST', 'resource' => 'supportCase', 'body' => ['body' => 'let me in'],
        ],
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake(DocumentVaultService::DISK);
    }

    /**
     * Every authenticated route that names a thing.
     *
     * Read from the router, so it grows on its own.
     *
     * @return list<string>
     */
    private static function parameterisedRoutes(): array
    {
        $uris = [];

        foreach (Route::getRoutes() as $route) {
            $uri = $route->uri();

            if (! str_starts_with($uri, 'api/v1/') || ! str_contains($uri, '{')) {
                continue;
            }

            $uris[$uri] = true;
        }

        return array_keys($uris);
    }

    public function test_every_route_that_names_a_thing_has_been_considered(): void
    {
        $declared = array_merge(
            array_keys(self::CUSTOMER_SCOPED),
            array_keys(self::NOT_CUSTOMER_SCOPED),
        );

        $undeclared = array_diff(self::parameterisedRoutes(), $declared);

        $this->assertSame(
            [],
            array_values($undeclared),
            'These routes take a parameter and nobody has said whether it '
            ."names a customer's record:\n  ".implode("\n  ", $undeclared)
            ."\nAdd each to CUSTOMER_SCOPED with a probe, or to "
            .'NOT_CUSTOMER_SCOPED with the reason.',
        );
    }

    public function test_the_declarations_describe_routes_that_exist(): void
    {
        // The other direction: a route renamed or removed leaves a
        // declaration claiming to cover something that is not there, and a
        // coverage list nobody prunes is a coverage list nobody trusts.
        $routes = self::parameterisedRoutes();

        foreach (array_merge(
            array_keys(self::CUSTOMER_SCOPED),
            array_keys(self::NOT_CUSTOMER_SCOPED),
        ) as $declared) {
            $this->assertContains(
                $declared,
                $routes,
                "[{$declared}] is declared here but is not a route any more.",
            );
        }
    }

    public function test_every_reason_is_a_reason(): void
    {
        foreach (self::NOT_CUSTOMER_SCOPED as $route => $why) {
            $this->assertGreaterThan(
                40,
                strlen($why),
                "[{$route}] is excused with a placeholder rather than a reason.",
            );
        }
    }

    /** @return iterable<string, array{string, array<string, mixed>}> */
    public static function customerScopedRoutes(): iterable
    {
        foreach (self::CUSTOMER_SCOPED as $uri => $spec) {
            yield $uri => [$uri, $spec];
        }
    }

    /**
     * The probe: the owner's record, the intruder's token.
     *
     * What is asserted is indistinguishability, not a particular status.
     * The property that stops somebody enumerating other customers' records
     * is that the answer to "here is an id that is not yours" is the same
     * as the answer to "here is an id that never existed" — whatever that
     * answer happens to be.
     *
     * Asserting 404 specifically would have been narrower and wrong. One
     * endpoint validates its body before it resolves the document and so
     * answers 422 to everything, including a document nobody has ever had.
     * That leaks nothing, and a test demanding 404 would have called it a
     * defect and had it "fixed" into a different shape for no gain.
     *
     * What is never acceptable is a 200, or a 500 from a controller that
     * assumed it would find something.
     *
     * @param  array<string, mixed>  $spec
     */
    #[DataProvider('customerScopedRoutes')]
    public function test_a_route_that_names_a_thing_does_not_answer_to_the_wrong_customer(
        string $uri,
        array $spec,
    ): void {
        $owner = $this->customer();
        $records = $this->recordsFor($owner);

        $intruder = $this->customer();
        Sanctum::actingAs($intruder);

        $path = '/'.str_replace(
            ['{application}', '{document}', '{goal}', '{conversation}',
                '{memory}', '{notification}', '{device}', '{case}',
                '{decision}', '{requirement}'],
            [$records['application'], $records['document'], $records['goal'],
                $records['conversation'], $records['memory'], $records['notification'],
                $records['device'], $records['supportCase'],
                $records['decision'], 'income'],
            $uri,
        );

        // The same request, against an id nobody has ever had.
        $absent = '/'.preg_replace('/\{[a-z]+\}/', '999999999', $uri);
        $absent = str_replace('999999999/review', 'income/review', $absent);
        $absent = preg_replace('#/documents/999999999$#', '/documents/999999999', $absent);

        $stolen = $this->send($spec['verb'], $path, $spec['body'] ?? []);
        $nonexistent = $this->send($spec['verb'], $absent, $spec['body'] ?? []);

        $this->assertGreaterThanOrEqual(
            400,
            $stolen->getStatusCode(),
            "[{$spec['verb']} {$uri}] answered {$stolen->getStatusCode()} to a "
            .'customer it does not belong to.',
        );

        $this->assertLessThan(
            500,
            $stolen->getStatusCode(),
            "[{$spec['verb']} {$uri}] threw rather than refusing — a "
            .'controller that assumed it would find something.',
        );

        $this->assertSame(
            $nonexistent->getStatusCode(),
            $stolen->getStatusCode(),
            "[{$spec['verb']} {$uri}] answers "
            .$stolen->getStatusCode().' for another customer\'s record and '
            .$nonexistent->getStatusCode().' for one that never existed. That '
            .'difference is how somebody discovers which ids are real.',
        );
    }

    private function send(string $verb, string $path, array $body)
    {
        return match ($verb) {
            'GET' => $this->getJson($path),
            'POST' => $this->postJson($path, $body),
            'DELETE' => $this->deleteJson($path),
        };
    }

    private function customer(): User
    {
        $user = User::factory()->create();
        $user->ensureProfiles();
        $user->customerProfile->update(['full_name' => 'Sample Customer', 'city' => 'Pune']);
        $user->financialProfile->update([
            'monthly_income' => 150000,
            'monthly_expenses' => 40000,
            'existing_emi' => 10000,
            'savings' => 600000,
        ]);
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * One of everything, owned by this customer.
     *
     * Created through the API where there is an endpoint for it, so the
     * probe runs against records made the way real ones are.
     *
     * @return array<string, string>
     */
    private function recordsFor(User $owner): array
    {
        $application = $this->postJson('/api/v1/applications', [
            'product_id' => 'prod_bl_b',
            'amount' => 1000000,
            'tenure_months' => 60,
        ])->assertCreated()->json('data.id');

        $path = tempnam(sys_get_temp_dir(), 'fynn').'.pdf';
        file_put_contents($path, "%PDF-1.4\n%%EOF\n");
        $document = $this->postJson('/api/v1/documents', [
            'file' => new UploadedFile($path, 'income.pdf', 'application/pdf', null, true),
            'type' => 'income',
        ])->assertCreated()->json('data.id');

        $goal = $this->postJson('/api/v1/goals', [
            'title' => 'Emergency fund',
            'category' => 'personal',
            'target_amount' => 100000,
            'target_date' => now()->addYear()->toDateString(),
        ])->assertCreated()->json('data.id');

        $conversation = $this->postJson('/api/v1/ai/conversations', [])
            ->assertSuccessful()->json('data.id');

        $supportCase = $this->postJson('/api/v1/support/cases', [
            'category' => 'general',
            'subject' => 'A question about my account',
            'description' => 'I would like to understand how this works.',
        ])->assertCreated()->json('data.id');

        $device = $this->postJson('/api/v1/notifications/devices', [
            'token' => 'owner-handset-'.$owner->getKey().'-abcdefghij',
            'platform' => 'ios',
        ])->assertSuccessful()->json('data.id');

        // Created by starting the application, above.
        $notification = $this->getJson('/api/v1/notifications')
            ->assertOk()->json('data.0.id');

        /*
         * A decision the owner explored.
         *
         * The application created above is itself a decision, so its
         * history entry is what the intruder is pointed at — a real record
         * rather than a synthetic one.
         */
        $decision = $this->getJson('/api/v1/decisions')
            ->assertOk()->json('data.0.id');

        $memory = $owner->aiMemories()->create([
            'key' => 'primary_intent',
            'value' => 'a home',
            'source' => 'conversation',
        ])->id;

        return [
            'application' => (string) $application,
            'document' => (string) $document,
            'goal' => (string) $goal,
            'conversation' => (string) $conversation,
            'memory' => (string) $memory,
            'notification' => (string) $notification,
            'device' => (string) $device,
            'supportCase' => (string) $supportCase,
            'decision' => (string) $decision,
        ];
    }
}
