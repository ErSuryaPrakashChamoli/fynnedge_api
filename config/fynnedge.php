<?php

/**
 * A retention period in days, or null when none is configured.
 *
 * Null is the meaningful value: it means nothing is deleted on a schedule.
 * An unset or empty variable never becomes zero, which would delete
 * everything immediately.
 */
$days = function (string $key): ?int {
    $value = env($key);

    return ($value === null || $value === '') ? null : max(1, (int) $value);
};

return [
    /*
     * The development customer's mobile number, used by DemoCustomerSeeder
     * and by the tests that assert against a known profile. Nothing in the
     * request path reads it: who a request belongs to has come from the
     * Sanctum token since Module 2, never from configuration.
     */
    'demo_mobile' => env('FYNNEDGE_DEMO_MOBILE', '9876543210'),

    /*
     * OTP delivery. 'log' writes the code to the Laravel log instead of
     * sending an SMS. Swap for a real provider without touching callers.
     */
    /*
     * Which catalogue answers customer product requests.
     *
     * 'sample'   the six fictional products written into
     *            MockProductRepository. The default, so a deployment that
     *            has never run the catalogue seeder still works and still
     *            says plainly that its products are invented.
     *
     * 'database' the persisted catalogue: providers, products, versions and
     *            provenance, administered through the console commands.
     *
     * The switch is explicit on purpose. Falling back to sample products
     * because a database table happened to be empty is exactly how invented
     * pricing reaches a customer who was promised real pricing.
     */
    'catalogue' => [
        'driver' => env('FYNNEDGE_CATALOGUE_DRIVER', 'sample'),
    ],

    /*
     * Provider integration.
     *
     * NO LIVE PROVIDER IS CONNECTED TO FYNNEDGE. 'fixture' is a fictional
     * provider used to prove the ingestion boundary works; nothing it does
     * reaches a network, and every product it creates carries a sample
     * provenance so it can never be shown as a real offer.
     *
     * A real adapter gets its own driver value here when a provider contract
     * actually exists. Two rules govern that day:
     *
     *   - Production must not run on 'fixture'. A deployment configured for
     *     a real provider that finds none must fail, not quietly serve
     *     invented products.
     *
     *   - A real provider failing is never permission to fall back to
     *     fixture or sample data. There is no fallback, deliberately.
     */
    'providers' => [
        'driver' => env('FYNNEDGE_PROVIDER_DRIVER', 'fixture'),

        // Bounded, so an ingestion run cannot hang. Seconds.
        'timeout' => (int) env('FYNNEDGE_PROVIDER_TIMEOUT', 15),
    ],

    /*
     * Credit bureau.
     *
     * NO LIVE BUREAU IS CONNECTED TO FYNNEDGE. The default is 'none', which
     * means the capability does not exist: credit-bureau consent is not
     * offered, no credit check can be requested, and a product's documented
     * minimum score is reported as something FynnEdge could not evaluate.
     *
     * 'fixture' is a fictional bureau used to exercise the boundary in
     * development and testing. It reaches no network and involves no real
     * person's credit information.
     *
     * A real bureau gets its own driver value when a contract exists, along
     * with its endpoint, credentials and sandbox flag — none of which belong
     * in the repository. Two rules hold on that day:
     *
     *   - production must not run on 'fixture';
     *   - a bureau failing is never permission to invent a score, reuse an
     *     older one as current, or assume anything about a customer's
     *     credit. There is no fallback.
     */
    'credit' => [
        'driver' => env('FYNNEDGE_CREDIT_DRIVER', 'none'),

        // Seconds. Bounded so a check cannot hang.
        'timeout' => (int) env('FYNNEDGE_CREDIT_TIMEOUT', 15),

        /*
         * How long a report is reused before another is requested.
         *
         * Bureau pulls cost money and may be recorded as enquiries, so a
         * repeated tap must not become a repeated pull. Thirty days is a
         * placeholder for development, not a policy: the real figure comes
         * from the bureau contract and from whatever the enquiry rules
         * require, neither of which exists yet.
         *
         * POLICY REQUIRED BEFORE ANY PRODUCTION BUREAU LAUNCH.
         */
        'reuse_days' => (int) env('FYNNEDGE_CREDIT_REUSE_DAYS', 30),

        /*
         * How many checks one customer may request in an hour.
         *
         * Far stricter than anything else in the API, because a bureau pull
         * may be billable and may be recorded as an enquiry in the
         * customer's name. Five is a development figure; the real one comes
         * from the bureau contract.
         */
        'checks_per_hour' => (int) env('FYNNEDGE_CREDIT_CHECKS_PER_HOUR', 5),
    ],

    /*
     * FynnScan — document understanding.
     *
     * NO LIVE DOCUMENT PROVIDER IS CONNECTED TO FYNNEDGE. `none` is the
     * default and means the capability does not exist: the processing
     * consent is not offered, and a scan request answers 501.
     *
     *   none      no provider. Documents are stored and never read.
     *   fixture   a fictional provider, for proving the boundary in tests.
     */
    'scan' => [
        'driver' => env('FYNNEDGE_SCAN_DRIVER', 'none'),

        /*
         * Which behaviour the fictional provider plays.
         *
         * Server-side only. Deliberately not selectable from a request: a
         * customer choosing their own extraction, or choosing whether it is
         * labelled sample data, would defeat the point of both.
         */
        'fixture_scenario' => env('FYNNEDGE_SCAN_FIXTURE_SCENARIO', 'good'),

        // Seconds. Bounded so a provider cannot hang a customer's screen.
        'timeout' => (int) env('FYNNEDGE_SCAN_TIMEOUT', 30),

        /*
         * How many scans one customer may request in an hour.
         *
         * Document intelligence is billed per page by every provider that
         * sells it, so a repeated tap must not become a repeated charge.
         * Ten is a development figure; the real one comes from a provider
         * contract that does not exist yet.
         */
        'scans_per_hour' => (int) env('FYNNEDGE_SCAN_PER_HOUR', 10),
    ],

    /*
     * KYC — identity and document verification.
     *
     * NO LIVE KYC PROVIDER IS CONNECTED TO FYNNEDGE. `none` is the default
     * and means the capability does not exist: the identity-verification
     * consent is not offered, and a verification request answers 501.
     *
     *   none      no provider. Documents are stored and never verified.
     *   fixture   a fictional service, for proving the boundary in tests.
     *
     * There is deliberately no fallback. A deployment configured for a real
     * provider whose credentials are absent returns kyc_provider_unavailable
     * rather than quietly using the fixture — a synthetic "verified" shown
     * as a real one is the worst failure this module could have.
     */
    'kyc' => [
        'driver' => env('FYNNEDGE_KYC_DRIVER', 'none'),

        /*
         * Which behaviour the fictional service plays.
         *
         * Server-side only. Deliberately not selectable from a request: a
         * customer choosing `verified` would be issuing their own
         * verification.
         */
        'fixture_scenario' => env('FYNNEDGE_KYC_FIXTURE_SCENARIO', 'verified'),

        // Seconds. Bounded so a provider cannot hang a customer's screen.
        'timeout' => (int) env('FYNNEDGE_KYC_TIMEOUT', 30),

        /*
         * Verifications per customer per hour.
         *
         * Identity checks are billed per call by every provider that sells
         * them, so a looping client must not become a looping charge. Five
         * is a development figure; the real one comes from a contract that
         * does not exist yet.
         */
        'checks_per_hour' => (int) env('FYNNEDGE_KYC_CHECKS_PER_HOUR', 5),
    ],

    /*
     * Lender application submission.
     *
     * NO LIVE SUBMISSION PROVIDER IS CONNECTED TO FYNNEDGE. There is no
     * lender, gateway, aggregator or DSA integration behind this product.
     * `none` is the default and means the capability does not exist: the
     * submission consent is not offered, and a submission answers 501.
     *
     *   none      no provider. Applications are recorded and sent nowhere.
     *   fixture   a fictional provider, for proving the boundary in tests.
     *
     * There is deliberately no fallback. A deployment configured for a real
     * provider whose adapter or credentials are absent returns
     * lender_provider_unavailable rather than quietly simulating a
     * submission — a customer told their application reached a lender when
     * it reached nothing is the worst failure this product could produce.
     */
    'lender' => [
        'driver' => env('FYNNEDGE_LENDER_DRIVER', 'none'),

        /*
         * Which behaviour the fictional provider plays.
         *
         * Server-side only. A request cannot choose it: a customer picking
         * an outcome would be writing their own submission result.
         */
        'fixture_scenario' => env('FYNNEDGE_LENDER_FIXTURE_SCENARIO', 'simulated'),

        // Seconds. Bounded so a provider cannot hang a customer's screen.
        'timeout' => (int) env('FYNNEDGE_LENDER_TIMEOUT', 30),

        /*
         * Submission attempts per customer per hour.
         *
         * Each one may create an application at an external party, so this
         * is the strictest limiter in the API. Three is a development
         * figure; the real one comes from a contract that does not exist.
         */
        'submissions_per_hour' => (int) env('FYNNEDGE_LENDER_PER_HOUR', 3),
    ],

    /*
     * Provider events — callbacks about applications already submitted.
     *
     * NO LIVE PROVIDER EVENT SOURCE IS CONNECTED TO FYNNEDGE. There is no
     * webhook contract, no signing secret and no endpoint documentation,
     * because there is no provider. `none` is the default and means the
     * ingress accepts nothing: an unknown provider code is a 404.
     *
     *   none      no event source. Nothing can report anything.
     *   fixture   a simulated source, for proving the boundary in tests.
     *             Refused in production regardless of this setting.
     *
     * PRODUCTION, on the day a real provider contract exists: its
     * authentication scheme (HMAC over the body, an asymmetric signature,
     * mTLS) comes from that contract and gets its own authenticator and its
     * own credentials here. None of it is guessed at in advance.
     */
    /*
     * Malware scanning for customer documents.
     *
     * NO SCANNER IS CONNECTED. `none` is the default and means nothing has
     * looked at any stored file — which is why no file may be sent to a
     * real external provider until one exists. That is enforced by
     * ExternalTransferGate, not merely documented.
     *
     * A simulated provider sends bytes nowhere, so it is unaffected: the
     * gate protects real destinations, and blocking development to protect
     * nobody would only teach people to route around it.
     */
    'vault' => [
        'malware_scanner' => env('FYNNEDGE_MALWARE_SCANNER', 'none'),
    ],

    /*
     * Retention.
     *
     * Every period here is null, and null means "keep it". FynnEdge does
     * not invent a retention period: how long a credit report, a document
     * extraction, an identity verification result or an operational event
     * record may be kept is a decision for product and legal, and a number
     * chosen by an engineer to make a table smaller would delete a
     * customer's record on a date nobody agreed.
     *
     * What exists is the mechanism. `php artisan fynn:retention` reports
     * what is held and how old it is, and deletes only what a configured
     * period actually covers — so the day a policy is decided, enforcing it
     * is a configuration change rather than a project.
     *
     * This is separate from consent withdrawal, which already deletes
     * immediately and unconditionally. These periods govern how long
     * something may be kept while consent still stands.
     */
    'retention' => [
        'credit_reports_days' => $days('FYNNEDGE_RETAIN_CREDIT_DAYS'),
        'document_scans_days' => $days('FYNNEDGE_RETAIN_SCAN_DAYS'),
        'kyc_verifications_days' => $days('FYNNEDGE_RETAIN_KYC_DAYS'),
        'provider_events_days' => $days('FYNNEDGE_RETAIN_EVENT_DAYS'),
    ],

    'provider_events' => [
        'driver' => env('FYNNEDGE_PROVIDER_EVENTS_DRIVER', 'none'),

        /*
         * The simulated source's shared secret.
         *
         * A real check, not a bypass: with no secret configured the
         * fixture authenticates nothing. It is emphatically not a proposal
         * for what a real provider should use.
         */
        'fixture_secret' => env('FYNNEDGE_PROVIDER_EVENTS_FIXTURE_SECRET'),

        /*
         * Deliveries accepted per provider per minute.
         *
         * Generous enough that a provider replaying a backlog is not
         * blocked, tight enough that the endpoint is not a free amplifier.
         * A real figure comes from a provider's own delivery behaviour.
         */
        'per_minute' => (int) env('FYNNEDGE_PROVIDER_EVENTS_PER_MINUTE', 120),
    ],

    /*
     |--------------------------------------------------------------------
     | Notifications
     |--------------------------------------------------------------------
     |
     | Notification records are always written — they are how a customer
     | understands their own application, and they do not depend on any
     | provider. Push delivery is the part that needs somewhere to send to.
     |
     | NO PUSH PROVIDER IS CONNECTED. There is no FCM integration, no APNs
     | integration and no credentials anywhere in this project. The driver
     | is 'none' and the only alternative is 'mock', which simulates and
     | says so. A real adapter arrives as a third driver.
     |
     */
    'notifications' => [
        'push' => [
            'driver' => env('FYNNEDGE_PUSH_DRIVER', 'none'),
        ],
    ],

    /*
     |--------------------------------------------------------------------
     | Support
     |--------------------------------------------------------------------
     |
     | NO ADVISOR SYSTEM, SUPPORT CRM, TICKETING SYSTEM OR CHAT PROVIDER IS
     | CONNECTED. Support cases live in FynnEdge's own tables and are read
     | by whoever looks at them; nothing is forwarded anywhere.
     |
     | These are rate limits and nothing else. There is no retention period
     | here because none has been decided — support history is a customer's
     | own record of what they asked and what they were told, and inventing
     | a deletion date to make the module feel finished would be deleting
     | it on a schedule nobody chose.
     |
     */
    'support' => [
        'cases_per_hour' => (int) env('FYNNEDGE_SUPPORT_CASES_PER_HOUR', 5),
        'messages_per_hour' => (int) env('FYNNEDGE_SUPPORT_MESSAGES_PER_HOUR', 20),
    ],

    'otp' => [
        'driver' => env('FYNNEDGE_OTP_DRIVER', 'log'),
        'length' => 6,
        'ttl_seconds' => (int) env('FYNNEDGE_OTP_TTL', 300),
        'resend_after_seconds' => 30,
        'max_attempts' => 5,
    ],
];
