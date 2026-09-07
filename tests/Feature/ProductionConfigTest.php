<?php

namespace Tests\Feature;

use App\Domain\Ai\FynnAiProvider;
use App\Domain\Otp\LogOtpSender;
use App\Domain\Otp\OtpSender;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\UnavailableDocumentUnderstandingProvider;
use App\Models\User;
use Database\Seeders\DemoCustomerSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use RuntimeException;
use Tests\TestCase;

/**
 * What the application refuses to do when it believes it is in production.
 *
 * Development conveniences are the ones that hurt most if they survive a
 * deploy: a customer nobody created, an OTP anyone can read, an error page
 * that describes the server. Each is checked here rather than trusted to a
 * deployment checklist.
 */
class ProductionConfigTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_missing_debug_setting_means_debug_is_off(): void
    {
        // A deployment that forgets APP_DEBUG entirely must get the quiet
        // behaviour, not the one that prints stack traces.
        $this->assertStringContainsString(
            "env('APP_DEBUG', false)",
            file_get_contents(config_path('app.php')),
            'APP_DEBUG does not default to false',
        );
    }

    public function test_with_debug_off_an_error_describes_nothing(): void
    {
        // Production shape, asserted rather than assumed: the suite runs
        // with debug on so failures stay readable, so it is turned off here.
        Config::set('app.debug', false);

        $user = User::factory()->create();
        $user->ensureProfiles();
        Sanctum::actingAs($user);

        foreach ([
            ['GET', '/api/v1/applications/999999'],
            ['GET', '/api/v1/not-a-thing'],
            ['GET', '/api/v1/goals/abc'],
            ['DELETE', '/api/v1/home'],
        ] as [$method, $uri]) {
            $content = $this->json($method, $uri)->getContent();

            foreach ([
                'exception', 'trace', 'vendor/', base_path(), '.php',
                'Illuminate', 'SQLSTATE', 'App\\',
            ] as $leak) {
                $this->assertStringNotContainsString(
                    $leak,
                    $content,
                    "{$method} {$uri} leaked \"{$leak}\" with debug off",
                );
            }
        }
    }

    public function test_the_development_otp_sender_refuses_to_run_in_production(): void
    {
        $sender = new LogOtpSender;

        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        $sender->send('9876543210', '123456');
    }

    public function test_no_development_hint_is_returned_in_production(): void
    {
        $sender = new LogOtpSender;

        $this->assertSame('123456', $sender->developmentHint('123456'));

        $this->app['env'] = 'production';
        $this->assertNull(
            $sender->developmentHint('123456'),
            'A production build handed the OTP back to the caller',
        );
    }

    public function test_the_demo_customer_cannot_be_seeded_into_production(): void
    {
        $this->app['env'] = 'production';

        $this->expectException(RuntimeException::class);
        (new DemoCustomerSeeder)->run();
    }

    public function test_the_demo_customer_is_still_seedable_in_development(): void
    {
        (new DemoCustomerSeeder)->run();

        $this->assertNotNull(
            User::where('mobile', config('fynnedge.demo_mobile'))->first(),
        );
    }

    public function test_no_request_path_reads_the_demo_customer(): void
    {
        // Who a request belongs to has come from the token since Module 2.
        // A configuration value that could name a customer is the kind of
        // thing that quietly becomes a fallback.
        $sources = array_merge(
            glob(app_path('Http/**/*.php')) ?: [],
            glob(app_path('Http/*/*/*.php')) ?: [],
            glob(app_path('Services/*.php')) ?: [],
            glob(app_path('Domain/*/*.php')) ?: [],
        );

        foreach ($sources as $file) {
            $this->assertStringNotContainsString(
                'demo_mobile',
                file_get_contents($file),
                basename($file).' reads the demo customer from configuration',
            );
        }
    }

    public function test_the_providers_that_cannot_do_the_work_say_so(): void
    {
        // FynnScan has no document-understanding provider, and binding one
        // that pretends would be worse than the honest unavailable answer.
        $this->assertInstanceOf(
            UnavailableDocumentUnderstandingProvider::class,
            app(DocumentUnderstandingProvider::class),
        );

        Config::set('services.fynn_ai.provider', 'none');
        $this->app->forgetInstance(FynnAiProvider::class);

        $provider = app(FynnAiProvider::class);
        $this->assertFalse(
            $provider->isLanguageModel(),
            'A provider claiming to be a language model was bound',
        );
    }

    public function test_the_otp_driver_must_be_one_the_app_can_actually_use(): void
    {
        Config::set('fynnedge.otp.driver', 'twilio-that-does-not-exist');
        $this->app->forgetInstance(OtpSender::class);

        // Fails loudly at boot rather than silently not sending anything.
        $this->expectException(RuntimeException::class);
        app(OtpSender::class);
    }

    public function test_no_secret_is_hardcoded_in_the_application(): void
    {
        $files = [];
        $directory = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(app_path())
        );
        foreach ($directory as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        foreach (array_merge($files, [config_path('fynnedge.php')]) as $file) {
            $source = file_get_contents($file);

            // An assignment of a literal to something secret-shaped, as
            // opposed to reading one from the environment.
            $this->assertDoesNotMatchRegularExpression(
                '/(password|secret|api_key|private_key|access_token)'
                .'\s*=>?\s*[\'"][A-Za-z0-9_\-]{12,}[\'"]/i',
                $source,
                basename($file).' appears to hardcode a credential',
            );
        }
    }
}
