<?php

namespace App\Services\Lender;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Consent\ConsentType;
use App\Domain\Lender\LenderApplicationMapper;
use App\Domain\Lender\LenderSubmissionProvider;
use App\Domain\Lender\LenderSubmissionUnavailable;
use App\Domain\Lender\ProviderMappingMissing;
use App\Domain\Lender\ProviderSubmissionResult;
use App\Domain\Lender\SubmissionOutcome;
use App\Models\ApplicationEvent;
use App\Models\ApplicationSubmission;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Notifications\CustomerNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sending an application to an external provider, and everything that has to
 * be true before one goes.
 *
 * Four rules shape this class.
 *
 * Consent first, and its own consent. Creating an application in FynnEdge is
 * not permission to send it anywhere; that is a separate decision the
 * customer makes with the destination in front of them.
 *
 * One tap, one submission. The attempt is created inside a row lock before
 * the provider is called, so eight simultaneous requests produce one
 * external submission rather than eight applications at a provider.
 *
 * An unknown outcome stays unknown. A request that left and was never
 * answered may be sitting with a provider. It is never recorded as a
 * failure, never automatically retried, and never offered to the customer
 * as a button that would send a second copy.
 *
 * And nothing financial is recalculated. The payload is built from the
 * application's historical snapshot; submission does not touch FynnScore,
 * FynnMatch, pricing, or the customer's profile.
 */
class LenderApplicationSubmissionService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly LenderApplicationMapper $mapper,
        private readonly CustomerNotifications $notifications,
    ) {}

    /**
     * The configured provider, resolved when it is needed.
     *
     * Not injected into the constructor on purpose. Which provider is in
     * force is server-side configuration, and configuration should decide
     * at the moment of use rather than at the moment this service happened
     * to be built — otherwise a long-lived instance keeps submitting
     * through a provider the deployment has since stopped using.
     */
    private function provider(): LenderSubmissionProvider
    {
        return app(LenderSubmissionProvider::class);
    }

    public function isConfigured(): bool
    {
        return config('fynnedge.lender.driver', 'none') !== 'none'
            && $this->provider()->isAvailable();
    }

    public function hasConsent(User $user): bool
    {
        return $this->consents->hasCurrentConsent(
            $user,
            ConsentType::LenderDataSharing,
        );
    }

    /**
     * Where this application's external submission stands.
     *
     * Never causes a provider call, so opening Application Detail cannot
     * submit anything or cost anything.
     */
    public function stateFor(LoanApplication $application): SubmissionState
    {
        if (! $this->isConfigured()) {
            return SubmissionState::unavailable($application, $this->provider());
        }

        $latest = $application->latestSubmission();

        if ($latest !== null) {
            return SubmissionState::of($application, $latest, $this->provider());
        }

        return $this->hasConsent($application->user)
            ? SubmissionState::notAttempted($application, $this->provider())
            : SubmissionState::consentRequired($application, $this->provider());
    }

    /**
     * Sends the application.
     *
     * @throws SubmissionNotConfigured|SubmissionConsentRequired
     * @throws ApplicationNotSubmittable|SubmissionAlreadyMade
     * @throws ProviderMappingMissing
     */
    public function submit(LoanApplication $application): SubmissionState
    {
        if (! $this->isConfigured()) {
            throw new SubmissionNotConfigured;
        }

        // Checked immediately before anything leaves, not earlier: a
        // customer who withdraws while the review screen is open must not
        // have their application sent anyway.
        if (! $this->hasConsent($application->user)) {
            throw new SubmissionConsentRequired;
        }

        // Built before the lock, so a product with no provider mapping fails
        // as an operational error without occupying a lock or creating an
        // attempt. Never a guessed product code.
        $payload = $this->mapper->map($application);

        /*
         * The attempt row is claimed inside a transaction with the
         * application locked, and the provider is called afterwards.
         *
         * That ordering is the whole defence against duplicate external
         * submissions: concurrent requests contend on the lock, exactly one
         * creates the attempt, and every other one sees a submission
         * already in flight or settled. A disabled button in Flutter is not
         * a substitute for this.
         */
        $attempt = DB::transaction(function () use ($application) {
            /** @var LoanApplication $fresh */
            $fresh = LoanApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if (! $this->isSubmittable($fresh)) {
                throw new ApplicationNotSubmittable;
            }

            $latest = $fresh->latestSubmission();

            if ($latest !== null && $latest->outcome()->isInFlightOrSettled()) {
                // Already sent, already in flight, or in an unknown state
                // that a second send could turn into two applications.
                throw new SubmissionAlreadyMade($latest);
            }

            $number = ($latest?->attempt ?? 0) + 1;

            return $fresh->submissions()->create([
                'user_id' => $fresh->user_id,
                'attempt' => $number,
                'provider' => $this->provider()->name(),
                'is_simulated' => $this->provider()->isSimulated(),
                'outcome' => SubmissionOutcome::Submitting->value,
                'product_id' => $fresh->product_id,
                'attempted_at' => now(),
            ]);
        });

        $key = $payload->idempotencyKey($attempt->attempt);

        $attempt->update([
            'provider_product_code' => $payload->providerProductCode,

            // Names only. The values a customer supplied are not copied
            // into a second table for the sake of debugging.
            'fields_sent' => $payload->fieldNames(),
            'idempotency_key' => $key,
        ]);

        $this->log('submission_requested', $attempt);

        try {
            $result = $this->provider()->submit($payload, $key);
        } catch (LenderSubmissionUnavailable $e) {
            return $this->recordFailure($application, $attempt, $e);
        }

        return $this->recordResult($application, $attempt, $result);
    }

    /**
     * Whether the application is in a state that can be sent.
     *
     * A cancelled one cannot, and neither can one a provider has already
     * decided on. Module 9's own statuses are the authority here.
     */
    private function isSubmittable(LoanApplication $application): bool
    {
        $status = $application->status();

        return in_array($status, [
            ApplicationStatus::Started,
            ApplicationStatus::Submitted,
        ], true);
    }

    private function recordResult(
        LoanApplication $application,
        ApplicationSubmission $attempt,
        ProviderSubmissionResult $result,
    ): SubmissionState {
        $attempt->update([
            'outcome' => $result->outcome->value,

            // Taken from the provider, never from the request that started
            // this. A client cannot relabel a simulated submission.
            'is_simulated' => $result->isSimulated,
            'provider_reference' => $result->providerReference,
            'rejection_reason' => $result->rejectionReason,
            'acknowledged_at' => $result->at,
        ]);

        $this->log(
            $result->outcome === SubmissionOutcome::RejectedByGateway
                ? 'submission_rejected'
                : 'submission_completed',
            $attempt->fresh(),
        );

        $this->recordEvent($application, $attempt->fresh(), $result->detail);

        return SubmissionState::of(
            $application->fresh(),
            $attempt->fresh(),
            $this->provider(),
        );
    }

    private function recordFailure(
        LoanApplication $application,
        ApplicationSubmission $attempt,
        LenderSubmissionUnavailable $e,
    ): SubmissionState {
        $outcome = $e->outcome();

        $attempt->update([
            'outcome' => $outcome->value,
            'failure_reason' => $e->reason,
            'acknowledged_at' => now(),
        ]);

        $this->log(
            $outcome === SubmissionOutcome::Unknown
                ? 'submission_unknown'
                : 'submission_failed',
            $attempt->fresh(),
            ['reason' => $e->reason],
        );

        $this->recordEvent($application, $attempt->fresh(), $e->customerMessage());

        return SubmissionState::of(
            $application->fresh(),
            $attempt->fresh(),
            $this->provider(),
        );
    }

    /**
     * The customer-visible history entry.
     *
     * Sourced as simulated whenever nothing left FynnEdge, so the timeline
     * cannot read as a real provider interaction. Only a genuine provider
     * acknowledgement is recorded as coming from a provider.
     */
    private function recordEvent(
        LoanApplication $application,
        ApplicationSubmission $attempt,
        string $detail,
    ): void {
        $application->recordEvent(
            $attempt->outcome()->label(),
            $detail,
            $attempt->is_simulated
                ? ApplicationEvent::SOURCE_SIMULATED
                : ApplicationEvent::SOURCE_PROVIDER,
            $attempt->acknowledged_at ?? now(),
        );

        /*
         * And, for the two outcomes worth saying out loud, tell them.
         *
         * The policy decides which: an unknown submission, because a
         * customer who assumes it failed will send a second one, and a
         * gateway rejection, because nothing was received and they can act
         * on that. Everything else here is FynnEdge's own bookkeeping —
         * the provider's later events are what report progress.
         *
         * Keyed on the attempt number, so a retry of attempt 2 does not
         * re-tell them about attempt 1.
         */
        $this->notifications->submissionSettled(
            $application,
            $attempt->outcome(),
            (int) $attempt->attempt,
        );
    }

    /**
     * Operational facts only.
     *
     * Never the payload, never a name, mobile number, income, PAN or
     * document. What is here is what an operator needs: which application,
     * which attempt, which provider, what outcome.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(
        string $event,
        ApplicationSubmission $attempt,
        array $context = [],
    ): void {
        Log::info($event, array_merge([
            'submission_id' => $attempt->getKey(),
            'application_id' => $attempt->loan_application_id,
            'user_id' => $attempt->user_id,
            'attempt' => $attempt->attempt,
            'provider' => $attempt->provider,
            'is_simulated' => $attempt->is_simulated,
            'outcome' => $attempt->outcome,
        ], $context));
    }
}

/** No submission provider is configured. */
class SubmissionNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No lender submission provider is configured.');
    }
}

/** The customer has not permitted external submission. */
class SubmissionConsentRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('External submission has not been permitted.');
    }
}

/** The application is not in a state that can be sent. */
class ApplicationNotSubmittable extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That application cannot be submitted.');
    }
}

/** An attempt has already been made and must not be duplicated. */
class SubmissionAlreadyMade extends RuntimeException
{
    public function __construct(public readonly ApplicationSubmission $submission)
    {
        parent::__construct('That application has already been submitted.');
    }
}
