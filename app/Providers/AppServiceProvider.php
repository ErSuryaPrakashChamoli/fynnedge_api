<?php

namespace App\Providers;

use App\Domain\Ai\DeterministicFynnAiProvider;
use App\Domain\Ai\FynnAiProvider;
use App\Domain\Ai\UnavailableFynnAiProvider;
use App\Domain\Application\ApplicationSubmissionGateway;
use App\Domain\Application\MockApplicationSubmissionGateway;
use App\Domain\Catalog\DatabaseProductRepository;
use App\Domain\Catalog\MockProductRepository;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Credit\Fixture\FixtureCreditBureauAdapter;
use App\Domain\Credit\Fixture\FixtureCreditNormalizer;
use App\Domain\Kyc\Fixture\FixtureKycVerificationProvider;
use App\Domain\Kyc\Fixture\FixtureScenario as KycFixtureScenario;
use App\Domain\Kyc\Fixture\IdentityDocumentNormalizer;
use App\Domain\Kyc\KycNormalizer;
use App\Domain\Kyc\KycVerificationProvider;
use App\Domain\Kyc\UnavailableKycVerificationProvider;
use App\Domain\Lender\Fixture\FixtureApplicationMapper;
use App\Domain\Lender\Fixture\FixtureLenderSubmissionProvider;
use App\Domain\Lender\Fixture\FixtureScenario as LenderFixtureScenario;
use App\Domain\Lender\LenderApplicationMapper;
use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\UnavailableLenderSubmissionProvider;
use App\Domain\Notifications\Fixture\MockNotificationDeliveryProvider;
use App\Domain\Notifications\NotificationDeliveryProvider;
use App\Domain\Notifications\UnavailableNotificationDeliveryProvider;
use App\Domain\Otp\LogOtpSender;
use App\Domain\Otp\OtpSender;
use App\Domain\Scan\DocumentNormalizer;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureDocumentUnderstandingProvider;
use App\Domain\Scan\Fixture\FixtureScenario;
use App\Domain\Scan\Fixture\IncomeDocumentNormalizer;
use App\Domain\Scan\UnavailableDocumentUnderstandingProvider;
use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\UnavailableMalwareScanner;
use App\Services\Catalogue\CatalogueAuditor;
use App\Services\ConsentService;
use App\Services\Credit\CreditBureauService;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // The OTP provider is chosen by config, so swapping in a real SMS
        // gateway is one binding and no caller changes.
        // The catalogue is fictional sample data today. A real lender feed
        // replaces this binding and nothing else changes.
        /*
         * The credit bureau.
         *
         * NO LIVE BUREAU IS CONNECTED. `none` is the default and means the
         * capability does not exist: the consent is not offered, no check
         * can be made, and a product's documented minimum score reports as
         * something FynnEdge could not evaluate.
         *
         * There is deliberately no fallback. A driver that is not
         * recognised binds nothing rather than quietly becoming the fixture.
         */
        $this->app->singleton(CreditBureauService::class, function ($app) {
            $driver = config('fynnedge.credit.driver', 'none');

            [$adapter, $normalizer] = match ($driver) {
                'fixture' => [
                    $app->make(FixtureCreditBureauAdapter::class),
                    $app->make(FixtureCreditNormalizer::class),
                ],
                default => [null, null],
            };

            return new CreditBureauService(
                $app->make(ConsentService::class),
                $adapter,
                $normalizer,
            );
        });

        // One auditor per request, so a console command can tell it how the
        // change arrived and every operation in that command records the
        // same actor.
        $this->app->singleton(CatalogueAuditor::class);

        // Sample by default. A deployment that has not populated the
        // catalogue gets the six fictional products and the labelling that
        // goes with them, rather than an empty shop or a silent fallback.
        $this->app->singleton(
            ProductRepository::class,
            fn ($app) => config('fynnedge.catalogue.driver') === 'database'
                ? $app->make(DatabaseProductRepository::class)
                : $app->make(MockProductRepository::class),
        );

        // FynnAI. The deterministic assistant is what FynnEdge has: it reads
        // the customer's real context and explains real figures, and it says
        // in every response that it is not a language model. Setting
        // FYNN_AI_PROVIDER=none binds the unavailable one instead.
        $this->app->singleton(
            FynnAiProvider::class,
            fn ($app) => config('services.fynn_ai.provider') === 'none'
                ? $app->make(UnavailableFynnAiProvider::class)
                : $app->make(DeterministicFynnAiProvider::class),
        );

        /*
         * Document understanding.
         *
         * NO LIVE DOCUMENT PROVIDER IS CONNECTED. The default driver is
         * `none`, which binds the provider that says so. `fixture` binds a
         * fictional one for proving the boundary. A real adapter adds its
         * own driver value here and nothing above this line changes.
         *
         * The scenario is read from configuration, never from a request: a
         * customer able to choose it would be choosing their own extraction.
         */
        $this->app->singleton(
            DocumentUnderstandingProvider::class,
            fn ($app) => match (config('fynnedge.scan.driver', 'none')) {
                'fixture' => new FixtureDocumentUnderstandingProvider(
                    FixtureScenario::tryFrom(
                        (string) config('fynnedge.scan.fixture_scenario', 'good')
                    ) ?? FixtureScenario::Good,
                ),
                default => $app->make(UnavailableDocumentUnderstandingProvider::class),
            },
        );

        // One family is supported, so one normalizer is bound. A second
        // family adds a resolver here rather than branching inside this one.
        $this->app->singleton(
            DocumentNormalizer::class,
            IncomeDocumentNormalizer::class,
        );

        /*
         * Malware scanning.
         *
         * NO SCANNER IS CONNECTED. The one bound here reports that a file
         * has not been checked — never that it is clean, which would turn
         * "nobody looked" into "we looked and it was fine" everywhere
         * downstream. A real scanner replaces this line and the gate above
         * it starts letting files through.
         */
        $this->app->singleton(
            MalwareScanner::class,
            fn ($app) => match (config('fynnedge.vault.malware_scanner', 'none')) {
                default => $app->make(UnavailableMalwareScanner::class),
            },
        );

        /*
         * Identity verification.
         *
         * NO LIVE KYC PROVIDER IS CONNECTED. The default driver is `none`,
         * which binds nothing that can verify. `fixture` binds a fictional
         * service for proving the boundary.
         *
         * There is deliberately no fallback: a deployment configured for a
         * real provider that has not been written yet gets an adapter that
         * refuses, not the fixture. A synthetic "verified" shown as a real
         * one is the worst failure this module could have.
         */
        $this->app->singleton(
            KycVerificationProvider::class,
            fn ($app) => match (config('fynnedge.kyc.driver', 'none')) {
                'fixture' => new FixtureKycVerificationProvider(
                    KycFixtureScenario::tryFrom(
                        (string) config('fynnedge.kyc.fixture_scenario', 'verified')
                    ) ?? KycFixtureScenario::Verified,
                ),
                default => $app->make(UnavailableKycVerificationProvider::class),
            },
        );

        $this->app->singleton(
            KycNormalizer::class,
            IdentityDocumentNormalizer::class,
        );

        /*
         * Lender application submission.
         *
         * NO LIVE SUBMISSION PROVIDER IS CONNECTED. The default driver is
         * `none`, which binds a provider that refuses. `fixture` binds a
         * fictional one for proving the boundary.
         *
         * No fallback: a driver naming a real provider that has not been
         * written gets the refusing adapter, not the fixture. A customer
         * told their application reached a lender when it reached nothing
         * is the worst failure this product could produce.
         */
        // Bound rather than a singleton: the provider is a thin object and
        // the driver is server-side configuration, so it is resolved from
        // config each time rather than captured at first use.
        $this->app->bind(
            LenderSubmissionProvider::class,
            fn ($app) => match (config('fynnedge.lender.driver', 'none')) {
                'fixture' => new FixtureLenderSubmissionProvider(
                    LenderFixtureScenario::tryFrom(
                        (string) config('fynnedge.lender.fixture_scenario', 'simulated')
                    ) ?? LenderFixtureScenario::Simulated,
                ),
                default => $app->make(UnavailableLenderSubmissionProvider::class),
            },
        );

        $this->app->singleton(
            LenderApplicationMapper::class,
            FixtureApplicationMapper::class,
        );

        // The submission boundary. A real lender gateway replaces this line
        // and nothing above it changes.
        $this->app->singleton(
            ApplicationSubmissionGateway::class,
            MockApplicationSubmissionGateway::class,
        );

        /*
         * Where a push notification would go.
         *
         * Bound, not a singleton, for the same reason as the others: the
         * driver is server-side config and must be readable per resolution
         * rather than captured at first use. 'none' is the default and the
         * unavailable provider is what that means.
         */
        $this->app->bind(
            NotificationDeliveryProvider::class,
            fn ($app) => match (config('fynnedge.notifications.push.driver', 'none')) {
                'mock' => new MockNotificationDeliveryProvider,
                default => $app->make(UnavailableNotificationDeliveryProvider::class),
            },
        );

        $this->app->bind(OtpSender::class, function () {
            return match (config('fynnedge.otp.driver')) {
                'log' => new LogOtpSender,
                default => throw new \RuntimeException(
                    'Unknown OTP driver: '.config('fynnedge.otp.driver'),
                ),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * OTP endpoints are the app's only unauthenticated write surface, so they
     * are limited by mobile number as well as by IP — one throttles a
     * targeted attack, the other a broad one.
     */
    private function configureRateLimiting(): void
    {
        // The per-number limits do the precise work: they stop someone
        // targeting one customer, and they cap SMS spend per number.
        //
        // The per-IP limits are deliberately loose. Indian mobile networks put
        // very large numbers of customers behind carrier-grade NAT, so a tight
        // per-IP cap locks out real people who share an address with a
        // stranger. It is a blunt instrument, kept only to blunt a broad
        // scripted attack from a single host.
        RateLimiter::for('otp-send', function (Request $request) {
            $mobile = (string) $request->input('mobile', '');

            return [
                Limit::perMinute(3)->by('otp-send:mobile:'.$mobile),
                Limit::perHour(10)->by('otp-send:mobile:'.$mobile),
                Limit::perMinute(60)->by('otp-send:ip:'.$request->ip()),
            ];
        });

        RateLimiter::for('otp-verify', function (Request $request) {
            $mobile = (string) $request->input('mobile', '');

            return [
                Limit::perMinute(6)->by('otp-verify:mobile:'.$mobile),
                Limit::perMinute(120)->by('otp-verify:ip:'.$request->ip()),
            ];
        });

        // Authenticated traffic. Deliberately generous: every request here
        // already carries a valid token, the sensitive endpoints have their
        // own limits, and a customer moving quickly through screens with
        // pull-to-refresh can legitimately make dozens of calls a minute.
        // Throttling them would cost trust and buy no security.
        RateLimiter::for('api', fn (Request $request) => Limit::perMinute(300)
            ->by($request->user()?->id ?: $request->ip()));

        // FynnAI writes: 20 a minute is far more than a person types and
        // far less than a loop. The global authenticated limit still
        // applies on top of it.
        RateLimiter::for('ai-write', fn (Request $request) => Limit::perMinute(20)
            ->by('ai-write:'.($request->user()?->id ?: $request->ip())));

        // Credit checks. A bureau pull may be billable and may be recorded
        // as an enquiry against the customer, so this is far stricter than
        // anything else in the API: it exists to stop a malicious or looping
        // client generating enquiries in someone's name. Configurable,
        // because the real figure comes from a bureau contract that does not
        // exist yet.
        RateLimiter::for('credit-check', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.credit.checks_per_hour', 5),
        )->by('credit-check:'.($request->user()?->id ?: $request->ip())));

        /*
         * FynnScan. Document intelligence is billed per page by every
         * provider that sells it, so a looping client must not become a
         * looping charge. Customer-bound rather than IP-bound, and far
         * stricter than the generic authenticated limit. Configurable,
         * because the real figure comes from a provider contract that does
         * not exist yet.
         */
        RateLimiter::for('scan-write', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.scan.scans_per_hour', 10),
        )->by('scan-write:'.($request->user()?->id ?: $request->ip())));

        /*
         * Identity verification. Billed per call by every provider that
         * sells it, and a repeated check learns nothing new about the same
         * file. Customer-bound and stricter than the scan limiter, because
         * a verification is a heavier operation on more sensitive data.
         */
        /*
         * Lender submission. The strictest limiter in the API, because each
         * attempt may create an application at an external party — a cost
         * measured in a customer's credit record rather than in money.
         */
        /*
         * Provider event ingress. Per provider rather than per customer —
         * there is no customer here — and per IP as a second key, so one
         * misbehaving source cannot drown out a legitimate one. Deliberately
         * not the OTP limiter: a provider replaying a backlog is normal, and
         * blocking it would lose real events.
         */
        RateLimiter::for('provider-events', fn (Request $request) => Limit::perMinute(
            (int) config('fynnedge.provider_events.per_minute', 120),
        )->by('provider-events:'.$request->route('provider').':'.$request->ip()));

        RateLimiter::for('lender-submit', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.lender.submissions_per_hour', 3),
        )->by('lender-submit:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('kyc-write', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.kyc.checks_per_hour', 5),
        )->by('kyc-write:'.($request->user()?->id ?: $request->ip())));

        // FynnVault writes. Every upload is up to 10 MB of stored bytes, so
        // the generous authenticated limit is the wrong instrument here: at
        // 300 a minute a single customer could write gigabytes. Twenty is
        // more than anyone adding documents from a phone will reach, and it
        // bounds what one account can cost.
        //
        // This limits the rate, not the total. How many documents a customer
        // may keep, and for how long, is a retention policy nobody has set —
        // see the limitations in the module report.
        RateLimiter::for('vault-write', fn (Request $request) => Limit::perMinute(20)
            ->by('vault-write:'.($request->user()?->id ?: $request->ip())));

        /*
         * Support.
         *
         * Two limits, because the two acts are different. Creating a case
         * is somebody deciding they need help; five an hour is far more
         * than anyone with a real problem will open, and it bounds what a
         * loop or a stuck retry can write.
         *
         * Adding information to a case that already exists is the ordinary
         * thing a customer does while being helped, so it is looser. Both
         * are per customer: a shared IP is a household, not an attacker,
         * and limiting support by IP would deny help to the second person
         * on a home connection.
         *
         * Deliberately generous rather than tight. A limiter that stops
         * somebody asking for help is a worse failure than one that lets a
         * duplicate through — and duplicates are already prevented by the
         * idempotency key.
         */
        RateLimiter::for('support-create', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.support.cases_per_hour', 5),
        )->by('support-create:'.($request->user()?->id ?: $request->ip())));

        RateLimiter::for('support-message', fn (Request $request) => Limit::perHour(
            (int) config('fynnedge.support.messages_per_hour', 20),
        )->by('support-message:'.($request->user()?->id ?: $request->ip())));
    }
}
