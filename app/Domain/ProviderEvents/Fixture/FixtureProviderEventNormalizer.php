<?php

namespace App\Domain\ProviderEvents\Fixture;

use App\Domain\ProviderEvents\CanonicalProviderEvent;
use App\Domain\ProviderEvents\ProviderEventMalformed;
use App\Domain\ProviderEvents\ProviderEventNormalizer;
use App\Domain\ProviderEvents\ProviderEventType;
use Illuminate\Support\Carbon;

/**
 * The simulated source's own event body, translated.
 *
 * Provider field       →  FynnEdge
 *   eventId            →  provider_event_id
 *   eventType          →  type
 *   submissionRef      →  provider_reference
 *   eventTime          →  occurred_at
 *   reasonCode         →  reason_code
 *
 * Deliberately written in a provider's dialect so the normalizer can be
 * tested translating it rather than merely asserted to. Everything else in
 * the body is dropped: a real provider callback arrives carrying whatever
 * that provider chooses to send, and none of it has a home here.
 */
class FixtureProviderEventNormalizer implements ProviderEventNormalizer
{
    /** The provider's own event names, mapped onto FynnEdge's taxonomy. */
    private const TYPES = [
        'SUBMISSION_ACK' => ProviderEventType::SubmissionReceived,
        'SUBMISSION_REJECTED' => ProviderEventType::SubmissionRejected,
        'IN_REVIEW' => ProviderEventType::ApplicationUnderReview,
        'ACTION_REQUIRED' => ProviderEventType::ActionRequired,
        'APPROVED' => ProviderEventType::ApplicationApproved,
        'DECLINED' => ProviderEventType::ApplicationDeclined,
        'DISBURSED' => ProviderEventType::ApplicationDisbursed,
        'CLOSED_BY_PROVIDER' => ProviderEventType::ApplicationCancelled,
    ];

    public function providerCode(): string
    {
        return FixtureProviderEventAuthenticator::PROVIDER_CODE;
    }

    public function normalize(array $payload): CanonicalProviderEvent
    {
        $rawType = $payload['eventType'] ?? null;

        if (! is_string($rawType) || ! isset(self::TYPES[$rawType])) {
            // An event type this normalizer has never seen is refused, not
            // guessed at. The plausible guess for a loan-application event
            // is that something advanced.
            throw new ProviderEventMalformed('unknown_event_type');
        }

        $reference = $payload['submissionRef'] ?? null;

        if (! is_string($reference) || trim($reference) === '') {
            throw new ProviderEventMalformed('missing_reference');
        }

        $occurredAt = $this->timestamp($payload['eventTime'] ?? null);

        return new CanonicalProviderEvent(
            providerEventId: isset($payload['eventId']) && is_string($payload['eventId'])
                ? $payload['eventId']
                : null,
            providerCode: $this->providerCode(),
            type: self::TYPES[$rawType],
            providerReference: trim($reference),
            occurredAt: $occurredAt,
            isSimulated: true,
            reasonCode: isset($payload['reasonCode']) && is_string($payload['reasonCode'])
                ? substr($payload['reasonCode'], 0, 60)
                : null,
        );
    }

    private function timestamp(mixed $value): Carbon
    {
        if (! is_string($value) || $value === '') {
            throw new ProviderEventMalformed('missing_timestamp');
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            throw new ProviderEventMalformed('unreadable_timestamp');
        }
    }
}
