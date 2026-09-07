<?php

namespace App\Services\ProviderEvents;

use App\Domain\ProviderEvents\CanonicalProviderEvent;
use App\Domain\ProviderEvents\EventProcessingStatus;
use App\Domain\ProviderEvents\StatusTransitionPolicy;
use App\Models\ApplicationEvent;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\ProviderEvent;
use App\Services\Notifications\CustomerNotifications;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * What a provider event means for an application, and what it changes.
 *
 * Five rules shape this class.
 *
 * The same event twice changes nothing twice. The idempotency key is unique
 * in the database, so a redelivery collides there and is answered from the
 * existing row — not from a check that could race with itself.
 *
 * An application only moves forward. The transition policy decides, and it
 * requires a strictly higher rank, so a late event cannot undo a later one
 * and two contradictory decisions do not flip a customer's outcome on
 * delivery order.
 *
 * Ownership comes from the provider's reference, never from the delivery. A
 * callback cannot name whose application it affects.
 *
 * State and timeline commit together. An application that says approved
 * while its timeline does not, or the reverse, would be a worse failure than
 * either alone, so both happen inside one transaction under a row lock.
 *
 * And nothing else happens. No document is touched, no OCR runs, no
 * verification is requested, no bureau is asked, no score moves. A provider
 * saying "under review" is a fact about their queue and nothing else.
 */
class SubmissionReconciliationService
{
    public function __construct(
        private readonly CustomerNotifications $notifications,
    ) {}

    /**
     * Records and applies one event.
     *
     * Always returns a row. An event that authenticated and could not be
     * matched, or that changes nothing, is stored with the reason — an
     * event FynnEdge silently dropped is one nobody can account for later.
     */
    public function reconcile(
        CanonicalProviderEvent $event,
        ?string $payloadHash = null,
    ): ProviderEvent {
        $key = $event->idempotencyKey();

        $existing = ProviderEvent::where('idempotency_key', $key)->first();

        if ($existing !== null) {
            // Already seen. The customer's application does not move again,
            // and no second timeline entry appears.
            $this->log('provider_event_duplicate', $event, $existing);

            return $existing;
        }

        $submission = $this->resolveSubmission($event);

        if ($submission === null) {
            // Authenticated, unmatchable. Recorded precisely because an
            // event that reaches nothing is what an operator needs to find.
            return $this->record(
                $event,
                $payloadHash,
                EventProcessingStatus::Failed,
                outcomeReason: 'unresolved_reference',
            );
        }

        return $this->apply($event, $payloadHash, $submission);
    }

    /**
     * Re-attempts an event that could not be applied the first time.
     *
     * The commonest failure is a race rather than a fault: a provider
     * calling back before FynnEdge has stored the reference the callback
     * quotes. That resolves itself the moment anything tries again, which
     * until now nothing did.
     *
     * The existing row is reused, never duplicated — the event is the same
     * event, and a second row would make one delivery look like two.
     */
    public function retry(ProviderEvent $record): ProviderEvent
    {
        if (! $record->isRetryable()) {
            return $record;
        }

        $record->increment('retry_count');
        $record->update(['last_retried_at' => now()]);

        $event = $record->toCanonical();
        $submission = $this->resolveSubmission($event);

        if ($submission === null) {
            // Still nothing to attach it to. The count records that we
            // looked, so an operator can tell a race worth waiting out from
            // something permanently broken.
            $this->log('provider_event_retry_unresolved', $event, $record->fresh());

            return $record->fresh();
        }

        return $this->apply($event, $record->payload_hash, $submission, $record);
    }

    /**
     * Finds the submission this event is about.
     *
     * Through the provider's own reference, and only that. There is
     * deliberately no path from a customer id, an application id or
     * anything else a delivery might carry to the application it changes.
     */
    private function resolveSubmission(CanonicalProviderEvent $event): ?ApplicationSubmission
    {
        return ApplicationSubmission::query()
            ->where('provider', $event->providerCode)
            ->where('provider_reference', $event->providerReference)
            ->latest('id')
            ->first();
    }

    private function apply(
        CanonicalProviderEvent $event,
        ?string $payloadHash,
        ApplicationSubmission $submission,

        /** Set when re-attempting: the row is updated, never duplicated. */
        ?ProviderEvent $existing = null,
    ): ProviderEvent {
        return DB::transaction(function () use ($event, $payloadHash, $submission, $existing) {
            /*
             * Locked before anything is read.
             *
             * Concurrent deliveries of competing events contend here, so
             * exactly one reads the current state, decides, and writes.
             * Without it, two events could both see "not yet approved" and
             * both apply.
             */
            $application = LoanApplication::query()
                ->whereKey($submission->loan_application_id)
                ->lockForUpdate()
                ->first();

            if ($application === null) {
                return $this->record(
                    $event,
                    $payloadHash,
                    EventProcessingStatus::Failed,
                    outcomeReason: 'application_missing',
                    existing: $existing,
                );
            }

            $currentStatus = $application->status();
            $currentOutcome = $submission->outcome();

            if (! StatusTransitionPolicy::applies($event->type, $currentStatus, $currentOutcome)) {
                /*
                 * Valid, and it changes nothing.
                 *
                 * A stale event, or one contradicting a decision already
                 * recorded. Kept with its reason, and the customer's
                 * timeline is left alone: they do not need to reason about
                 * an event FynnEdge did not act on.
                 */
                $record = $this->record(
                    $event,
                    $payloadHash,
                    EventProcessingStatus::Ignored,
                    $submission,
                    $application,
                    StatusTransitionPolicy::reasonForIgnoring(
                        $event->type,
                        $currentStatus,
                        $currentOutcome,
                    ),
                    $existing,
                );

                $this->log('provider_event_ignored', $event, $record);

                return $record;
            }

            $outcome = StatusTransitionPolicy::submissionOutcomeFor($event->type);
            $status = StatusTransitionPolicy::applicationStatusFor($event->type);

            if ($outcome !== null && $outcome !== $currentOutcome) {
                $submission->update([
                    'outcome' => $outcome->value,
                    'acknowledged_at' => $event->occurredAt,
                ]);
            }

            if ($status !== null) {
                // forceFill, because a provider decision is not a fillable
                // customer field and must not become one.
                $application->forceFill(['status' => $status->value])->save();
            }

            /*
             * The customer's timeline, in the same transaction.
             *
             * Sourced as the provider only when a provider genuinely said
             * it. A simulated delivery is marked simulated all the way
             * through, so the app renders it differently and nobody reads
             * a fixture as a lender.
             */
            $application->recordEvent(
                $event->type->timelineLabel(),
                $event->type->timelineDetail(),
                $event->isSimulated
                    ? ApplicationEvent::SOURCE_SIMULATED
                    : ApplicationEvent::SOURCE_PROVIDER,
                $event->occurredAt,
            );

            $record = $this->record(
                $event,
                $payloadHash,
                EventProcessingStatus::Processed,
                $submission,
                $application,
                existing: $existing,
            );

            /*
             * And, if this is worth telling the customer, tell them.
             *
             * Here rather than anywhere else, for two reasons. It is inside
             * the transaction, so a notification cannot survive a
             * reconciliation that rolls back. And it is on the applied
             * branch only — an event that was stale or contradicted
             * returned above, so the notification layer inherits Module
             * 28's ordering rather than reimplementing it and cannot
             * announce a review after a disbursal.
             *
             * The provider event's own key seeds the notification's, so a
             * redelivered event produces one notification and a retried
             * one produces none.
             */
            $this->notifications->providerEvent(
                $event->type,
                $application,
                $event->idempotencyKey(),
            );

            $this->log('provider_event_processed', $event, $record);

            return $record;
        });
    }

    private function record(
        CanonicalProviderEvent $event,
        ?string $payloadHash,
        EventProcessingStatus $status,
        ?ApplicationSubmission $submission = null,
        ?LoanApplication $application = null,
        ?string $outcomeReason = null,
        ?ProviderEvent $existing = null,
    ): ProviderEvent {
        $attributes = [
            'provider_code' => $event->providerCode,
            'idempotency_key' => $event->idempotencyKey(),
            'event_type' => $event->type->value,
            'provider_reference' => $event->providerReference,
            'is_simulated' => $event->isSimulated,
            'payload_hash' => $payloadHash,
            'processing_status' => $status->value,
            'reason_code' => $event->reasonCode,
            'outcome_reason' => $outcomeReason,
            'loan_application_id' => $application?->getKey()
                ?? $submission?->loan_application_id,
            'application_submission_id' => $submission?->getKey(),
            'occurred_at' => $event->occurredAt,
            'received_at' => now(),
            'processed_at' => $status === EventProcessingStatus::Processed
                ? now()
                : null,
        ];

        if ($existing !== null) {
            /*
             * A retry updates the row it is retrying.
             *
             * The identity fields are left alone — it is the same event,
             * and its key, provider and arrival time have not changed. Only
             * what became of it has.
             */
            $existing->update(Arr::except($attributes, [
                'provider_code', 'idempotency_key', 'event_type',
                'provider_reference', 'is_simulated', 'payload_hash',
                'occurred_at', 'received_at',
            ]));

            return $existing->fresh();
        }

        return ProviderEvent::create($attributes);
    }

    /**
     * Operational facts only.
     *
     * Never the payload, never a name, income, PAN, document or bureau
     * figure — a provider callback may carry any of those and none of them
     * belongs in a log line. What is here is what an operator needs: which
     * provider, which event, which application, what came of it.
     */
    private function log(
        string $message,
        CanonicalProviderEvent $event,
        ProviderEvent $record,
    ): void {
        Log::info($message, [
            'provider_code' => $event->providerCode,
            'event_type' => $event->type->value,

            // The record's own id, not the provider's — that one is
            // hidden, and repeating it in logs would spread it around.
            'provider_event_id' => $record->getKey(),

            'application_id' => $record->loan_application_id,
            'processing_status' => $record->processing_status,
            'is_simulated' => $record->is_simulated,
        ]);
    }
}
