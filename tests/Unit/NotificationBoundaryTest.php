<?php

namespace Tests\Unit;

use App\Domain\Notifications\DeepLink;
use App\Domain\Notifications\MarketingCommunication;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationDecision;
use App\Domain\Notifications\NotificationPreferences;
use App\Domain\Notifications\NotificationType;
use App\Domain\Notifications\RefusedNotificationContent;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * What a notification is allowed to be.
 *
 * NotificationDecision is the canonical object every producer must build,
 * which is the lesson Modules 23–28 taught three times: a policy enforced
 * only by the caller that already obeys it protects nothing once a second
 * caller exists. So the rules live in its constructor and these tests are
 * what prove they are rules rather than comments.
 */
class NotificationBoundaryTest extends TestCase
{
    private function decide(
        string $title = 'The provider approved your application',
        string $body = 'The provider has approved your application.',
        ?string $link = null,
        NotificationType $type = NotificationType::ApplicationApproved,
    ): NotificationDecision {
        return NotificationDecision::of(
            type: $type,
            title: $title,
            body: $body,
            idempotencyKey: 'application:1:provider:evt_1',
            deepLink: $link,
        );
    }

    // --- What a caller cannot decide ---------------------------------------

    public function test_a_caller_cannot_choose_the_category(): void
    {
        // Derived from the event, so a document problem cannot be filed
        // under goals and a caller has nothing to get wrong.
        $this->assertSame(
            NotificationCategory::Application,
            $this->decide()->category(),
        );

        $this->assertSame(
            NotificationCategory::Document,
            $this->decide(type: NotificationType::DocumentSafetyIssue)->category(),
        );
    }

    public function test_a_caller_cannot_choose_whether_it_is_optional(): void
    {
        // The property that decides whether a preference may suppress a
        // push. A caller able to set it could classify "action required" as
        // promotional.
        $this->assertTrue($this->decide()->isTransactional());

        $this->assertFalse(
            $this->decide(type: NotificationType::FynnScoreChanged)->isTransactional(),
        );
    }

    public function test_every_type_belongs_to_exactly_one_category(): void
    {
        foreach (NotificationType::cases() as $type) {
            $this->assertInstanceOf(NotificationCategory::class, $type->category());
        }
    }

    // --- Content refused ---------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function refusedText(): iterable
    {
        yield 'a PAN' => ['Your PAN AAAAA0000A was verified'];
        yield 'an Aadhaar number' => ['Verified against 000000000000'];
        yield 'an account number' => ['Paid into 1234567890'];
        yield 'a storage key path' => ['See documents/u1/file.pdf'];
        yield 'a storage key name' => ['Stored as 01M1XB609W22BEYNGVJFTWH2R2.pdf'];
        yield 'a content digest' => ['Digest '.str_repeat('a', 64)];
        yield 'an API token' => ['Use token 83|KhEWCf1NzowHzvcbee057hb4vS415Cbui'];
        yield 'a bearer header' => ['Bearer eyJhbGciOiJIUzI1NiJ9abc'];
    }

    #[DataProvider('refusedText')]
    public function test_text_shaped_like_data_a_notification_must_not_carry_is_refused(string $text): void
    {
        $this->expectException(RefusedNotificationContent::class);

        $this->decide(body: $text);
    }

    #[DataProvider('refusedText')]
    public function test_the_same_rule_applies_to_the_title(string $text): void
    {
        $this->expectException(RefusedNotificationContent::class);

        $this->decide(title: $text);
    }

    public function test_the_refusal_does_not_repeat_the_value_it_refused(): void
    {
        try {
            $this->decide(body: 'Your PAN AAAAA0000A was verified');
            $this->fail('expected a refusal');
        } catch (RefusedNotificationContent $e) {
            // An exception that quoted the value would have written it to
            // the log the rule exists to keep it out of.
            $this->assertStringNotContainsString('AAAAA0000A', $e->getMessage());
            $this->assertSame('pan', $e->rule);
        }
    }

    public function test_ordinary_copy_is_not_refused(): void
    {
        // A boundary that refuses everything is not a boundary. Amounts,
        // references and percentages all survive.
        $decision = $this->decide(
            body: 'Your application FE-2026-000123 for 5,00,000 at 11.5% is '
                .'saved. You are 75% of the way to your goal.',
        );

        $this->assertStringContainsString('FE-2026-000123', $decision->body);
    }

    public function test_empty_and_oversized_copy_are_refused(): void
    {
        $this->expectException(RefusedNotificationContent::class);
        $this->decide(title: '   ');
    }

    public function test_a_body_longer_than_a_notification_is_refused(): void
    {
        $this->expectException(RefusedNotificationContent::class);
        $this->decide(body: str_repeat('word ', 200));
    }

    // --- Links -------------------------------------------------------------

    /** @return iterable<string, array{string}> */
    public static function validLinks(): iterable
    {
        yield 'applications' => [DeepLink::applications()];
        yield 'one application' => [DeepLink::application(42)];
        yield 'the vault' => [DeepLink::vault()];
        yield 'one document' => [DeepLink::document(7)];
        yield 'the score' => [DeepLink::fynnScore()];
        yield 'goals' => [DeepLink::goals()];
        yield 'support' => [DeepLink::support()];
    }

    #[DataProvider('validLinks')]
    public function test_a_link_from_the_contract_is_accepted(string $link): void
    {
        $this->assertSame($link, $this->decide(link: $link)->deepLink);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidLinks(): iterable
    {
        yield 'a screen that does not exist' => ['fynnedge://loans/1'];
        yield 'a goal, which has no screen' => ['fynnedge://goals/3'];
        yield 'an http url' => ['https://example.com/pay'];
        yield 'a scheme that is not ours' => ['otherapp://applications/1'];
        yield 'a traversal' => ['fynnedge://applications/../../admin'];
        yield 'an identifier that is not one' => ['fynnedge://applications/abc'];
        yield 'a query smuggled in' => ['fynnedge://applications/1?token=x'];
        yield 'nothing at all' => [''];
    }

    #[DataProvider('invalidLinks')]
    public function test_a_link_the_router_cannot_open_is_refused(string $link): void
    {
        $this->expectException(RefusedNotificationContent::class);

        $this->decide(link: $link);
    }

    public function test_no_link_is_allowed(): void
    {
        $this->assertNull($this->decide(link: null)->deepLink);
    }

    // --- Preferences -------------------------------------------------------

    public function test_only_categories_with_nothing_transactional_can_be_muted(): void
    {
        $this->assertSame(
            ['financial_health', 'goal'],
            NotificationPreferences::mutableCategoryIds(),
        );
    }

    public function test_mutability_is_derived_rather_than_listed(): void
    {
        /*
         * The property this rests on: every category holding even one
         * transactional type is immutable, decided from the types
         * themselves. Adding a transactional type to a mutable category
         * closes it automatically, with nobody having to remember.
         */
        foreach (NotificationCategory::cases() as $category) {
            $types = array_filter(
                NotificationType::cases(),
                fn (NotificationType $t) => $t->category() === $category,
            );

            $anyTransactional = false;

            foreach ($types as $type) {
                $anyTransactional = $anyTransactional || $type->isTransactional();
            }

            $this->assertSame(
                ! $anyTransactional && $types !== [],
                NotificationPreferences::isMutable($category),
                "[{$category->value}] is mutable when it should not be, or the reverse.",
            );
        }
    }

    public function test_an_empty_category_is_not_mutable_by_default(): void
    {
        // System has no types yet. A category becoming mutable by virtue of
        // being empty would be exactly backwards for the one reserved for
        // service messages.
        $this->assertFalse(
            NotificationPreferences::isMutable(NotificationCategory::System),
        );
    }

    // --- Marketing ---------------------------------------------------------

    public function test_no_notification_type_is_marketing(): void
    {
        $this->assertFalse(MarketingCommunication::isAvailable());

        // And there is no category for it, which is what stops a marketing
        // preference ever being able to suppress a service message.
        $this->assertNotContains(
            'marketing',
            array_map(fn (NotificationCategory $c) => $c->value, NotificationCategory::cases()),
        );
    }
}
