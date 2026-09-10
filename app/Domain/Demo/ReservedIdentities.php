<?php

namespace App\Domain\Demo;

/**
 * The mobile numbers that belong to test and demo customers, listed exactly.
 *
 * Written out one by one rather than matched by prefix. A prefix is a rule
 * about numbers nobody has seen: `90000001%` looks reserved until the day a
 * real customer signs up on one, and the cleanup command would then delete
 * a real person's applications, documents and consents without anybody
 * having decided that. Every number here was chosen by a seeder or a test,
 * so the list is knowable, and a number not on it is never touched.
 *
 * Two sources, because two things create customers that are not people:
 * this application's own fixture seeder, and the Flutter app's contract
 * test, which signs in against a running backend and leaves the accounts it
 * made. Both are development artefacts and both need removing before a
 * database faces anybody real.
 *
 * The baseline development customer is deliberately absent from both lists.
 * It is the one the Flutter app's own mock data mirrors, and the one tests
 * assert a known profile against; removing it would break the thing it
 * exists for. Its number is configured rather than written here, and this
 * class does not read that setting — nothing in `app/` may, because who a
 * request belongs to comes from the token and never from configuration.
 */
final class ReservedIdentities
{
    /**
     * Written by DemoFixtureSeeder — four invented customers, one per
     * application outcome, so the admin panel has something to show.
     *
     * @var array<string, string>
     */
    public const FIXTURE_MOBILES = [
        'approved' => '9000000101',
        'in_review' => '9000000102',
        'declined' => '9000000103',
        'starting' => '9000000104',
    ];

    /**
     * Created by the Flutter contract test as it runs, not by any seeder.
     *
     * `../fynnedge/test/api_contract_test.dart` declares these as named
     * constants and signs in with them against a live backend, so each run
     * leaves a customer behind along with whatever the test made them do.
     * They are listed here because nothing on this side of the wire creates
     * them, and without the list the documented cleanup misses them.
     *
     * @var array<string, string>
     */
    public const CONTRACT_TEST_MOBILES = [
        'otp_round_trip' => '9000000001',
        'wrong_code' => '9000000002',
        'replay' => '9000000003',
        'logout' => '9000000004',
    ];

    /**
     * Every reserved number, for the cleanup command to match against.
     *
     * @return list<string>
     */
    public static function all(): array
    {
        return array_values(array_merge(
            self::FIXTURE_MOBILES,
            self::CONTRACT_TEST_MOBILES,
        ));
    }

    /**
     * Whether a number belongs to a test or demo account.
     *
     * An exact comparison. Nothing here does a prefix or pattern match, so
     * a real customer cannot be caught by widening a rule.
     */
    public static function isReserved(string $mobile): bool
    {
        return in_array($mobile, self::all(), strict: true);
    }
}
