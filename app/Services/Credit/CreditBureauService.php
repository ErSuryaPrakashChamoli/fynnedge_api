<?php

namespace App\Services\Credit;

use App\Domain\Consent\ConsentType;
use App\Domain\Credit\CreditBureauAdapter;
use App\Domain\Credit\CreditBureauUnavailable;
use App\Domain\Credit\CreditNormalizationFailed;
use App\Domain\Credit\CreditNormalizer;
use App\Domain\Credit\CreditProfile;
use App\Domain\Credit\CreditReportStatus;
use App\Models\CreditReport;
use App\Models\User;
use App\Services\ConsentService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Asking a bureau about a customer, and keeping almost none of the answer.
 *
 * Four rules run through all of it:
 *
 * CONSENT FIRST, ALWAYS. No adapter is reached without a current grant. Not
 * a stale one, not a superseded one — the version the customer agreed to has
 * to be the version in force. A check performed on permission somebody gave
 * to different wording is a check nobody agreed to.
 *
 * REUSE BEFORE REQUESTING. A bureau pull may cost money and may be recorded
 * as an enquiry against the customer, so a repeated tap does not become a
 * repeated pull. A recent report is returned as what it is: a report
 * obtained on a date, not a live reading.
 *
 * NOTHING IS INVENTED ON FAILURE. No score, no default, no reuse of an older
 * report as though it were current, and no assumption in either direction
 * about what a customer's credit looks like.
 *
 * ALMOST NOTHING IS KEPT. The normalizer discards the report's personal
 * detail before this class ever sees it; what is stored is a score, its
 * model, its scale and a timestamp.
 */
class CreditBureauService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly ?CreditBureauAdapter $adapter = null,
        private readonly ?CreditNormalizer $normalizer = null,
    ) {}

    /** Whether a bureau exists to ask at all. */
    public function isConfigured(): bool
    {
        return $this->adapter !== null && $this->normalizer !== null;
    }

    /**
     * Where this customer stands, without asking a bureau anything.
     *
     * Safe to call on any screen: it reads what FynnEdge already holds and
     * never triggers a pull. Bureau checks happen only when a customer asks
     * for one.
     */
    public function status(User $user): CreditReportStatus
    {
        if (! $this->isConfigured()) {
            return CreditReportStatus::NotConfigured;
        }

        if ($this->latest($user) !== null) {
            return CreditReportStatus::Available;
        }

        return $this->hasConsent($user)
            ? CreditReportStatus::NotRequested
            : CreditReportStatus::ConsentRequired;
    }

    /** The most recent report FynnEdge holds, if any. */
    public function profile(User $user): ?CreditProfile
    {
        return $this->latest($user)?->toProfile();
    }

    /**
     * Obtains this customer's credit information.
     *
     * Returns an existing report when one is recent enough, rather than
     * paying for and recording another enquiry.
     *
     * @throws CreditConsentRequired when the customer has not permitted it
     * @throws CreditBureauNotConfigured when no bureau exists
     * @throws CreditBureauUnavailable when the bureau could not answer
     */
    public function check(User $user, bool $force = false): CreditProfile
    {
        if (! $this->isConfigured()) {
            throw new CreditBureauNotConfigured;
        }

        // Checked immediately before the request, not earlier: a customer
        // who withdraws while a screen is open must not have a bureau asked
        // about them anyway.
        if (! $this->hasConsent($user)) {
            throw new CreditConsentRequired;
        }

        if (! $force) {
            $existing = $this->reusable($user);

            if ($existing !== null) {
                return $existing->toProfile();
            }
        }

        $this->log('credit_check_requested', $user);

        try {
            $response = $this->adapter->fetch($user);
            $profile = $this->normalizer->normalize($response);
        } catch (CreditNormalizationFailed $e) {
            // A response FynnEdge cannot read is a response it does not act
            // on. The reason stays server-side.
            $this->log('credit_check_failed', $user, ['stage' => 'normalize']);

            throw new CreditBureauUnavailable(
                $this->adapter->bureau(),
                'the response could not be read',
                $e,
            );
        } catch (CreditBureauUnavailable $e) {
            $this->log('credit_check_failed', $user, ['stage' => 'fetch']);

            throw $e;
        }

        // Consent is re-checked after the answer comes back as well. The
        // request is already made and paid for by then, but a customer who
        // withdrew while it was in flight does not get their credit
        // information stored.
        if (! $this->hasConsent($user)) {
            $this->log('credit_check_discarded', $user);

            throw new CreditConsentRequired;
        }

        $this->store($user, $profile, $response['bureauReference'] ?? null);
        $this->log('credit_check_completed', $user);

        return $profile;
    }

    /**
     * Removes everything FynnEdge holds about this customer's credit.
     *
     * Called when bureau consent is withdrawn. The reports go entirely
     * rather than being retained-but-unused: FynnEdge asked for them on a
     * permission that no longer stands, and there is no other purpose it
     * could honestly claim for keeping them.
     *
     * The consent ledger itself is append-only and untouched — that a
     * customer granted and later withdrew is a record that has to survive.
     */
    public function forget(User $user): int
    {
        $count = $user->creditReports()->count();

        $user->creditReports()->delete();

        if ($count > 0) {
            $this->log('credit_reports_deleted', $user, ['count' => $count]);
        }

        return $count;
    }

    private function hasConsent(User $user): bool
    {
        $type = ConsentType::CreditBureauCheck;

        if (! $type->isAvailable()) {
            return false;
        }

        // hasCurrentConsent, not merely "granted at some point": a consent
        // superseded by new wording has to be given again before FynnEdge
        // acts on it.
        return $this->consents->hasCurrentConsent($user, $type);
    }

    private function latest(User $user): ?CreditReport
    {
        return $user->creditReports()->first();
    }

    /**
     * A report recent enough to answer with instead of asking again.
     *
     * The window is configuration, not policy — see config/fynnedge.php.
     */
    private function reusable(User $user): ?CreditReport
    {
        $report = $this->latest($user);

        if ($report === null) {
            return null;
        }

        $days = (int) config('fynnedge.credit.reuse_days', 30);

        return $report->obtained_at->greaterThan(Carbon::now()->subDays($days))
            ? $report
            : null;
    }

    private function store(User $user, CreditProfile $profile, mixed $reference): void
    {
        $user->creditReports()->create([
            'bureau' => $profile->bureau->value,
            'score' => $profile->score,
            'score_model' => $profile->scoreModel,
            'score_min' => $profile->scoreMin,
            'score_max' => $profile->scoreMax,
            'obtained_at' => $profile->obtainedAt,
            'bureau_reference' => is_string($reference) ? $reference : null,
        ]);
    }

    /**
     * Operational events only.
     *
     * That a check happened, and how it ended. Never the score, never the
     * response, never anything else from the report — a log is the easiest
     * place in a system for sensitive data to end up and the hardest to get
     * it back out of.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, User $user, array $context = []): void
    {
        Log::info('Credit bureau', [
            'event' => $event,
            'user_id' => $user->id,
            ...$context,
        ]);
    }
}

/** The customer has not permitted a credit check, or has withdrawn it. */
class CreditConsentRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Credit bureau consent is required.');
    }
}

/** No bureau is configured, so no check can be made. */
class CreditBureauNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No credit bureau is connected.');
    }
}
