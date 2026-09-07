<?php

namespace App\Services;

use App\Domain\Consent\ConsentStatus;
use App\Domain\Consent\ConsentType;
use App\Models\Consent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Recording and reading consent.
 *
 * Every write appends. Nothing here updates or removes a row, and the model
 * itself refuses to be edited, so the history cannot be quietly tidied by a
 * future caller that means well.
 */
class ConsentService
{
    /**
     * The customer's position now: their most recent decision on each type,
     * keyed by type.
     *
     * @return array<string, Consent>
     */
    public function current(User $user): array
    {
        $latest = $user->consents()
            ->latestPerType()
            ->get()
            ->keyBy('type');

        return $latest->all();
    }

    /** Every decision they have ever made, newest first. */
    public function history(User $user): Collection
    {
        return $user->consents()
            ->orderByDesc('id')
            ->get();
    }

    /**
     * Records a grant.
     *
     * Granting something already granted under the same version adds no row:
     * a repeated tap, a retry or a second device is the same decision, and a
     * ledger full of identical entries makes the real changes harder to find.
     */
    public function grant(User $user, ConsentType $type, string $source): Consent
    {
        if (! $type->isAvailable()) {
            throw new RuntimeException('consent_unavailable');
        }

        $version = $type->currentVersion();

        return DB::transaction(function () use ($user, $type, $version, $source) {
            $existing = $this->currentFor($user, $type);

            if (
                $existing !== null
                && $existing->isGranted()
                && $existing->version === $version
            ) {
                return $existing;
            }

            return $user->consents()->create([
                'type' => $type->value,
                'version' => $version,
                'status' => ConsentStatus::Granted->value,
                'granted_at' => now(),
                'source' => $source,
            ]);
        });
    }

    /**
     * Records a withdrawal.
     *
     * Required consents are refused here. Withdrawing one is not a settings
     * change — it is ending the relationship — and it must never be something
     * a customer does by tapping a toggle next to marketing preferences.
     */
    public function withdraw(User $user, ConsentType $type, string $source): Consent
    {
        if ($type->isRequired()) {
            throw new RuntimeException('consent_required');
        }

        if (! $type->isAvailable()) {
            throw new RuntimeException('consent_unavailable');
        }

        return DB::transaction(function () use ($user, $type, $source) {
            $existing = $this->currentFor($user, $type);

            if ($existing === null) {
                throw new RuntimeException('consent_not_granted');
            }

            if (! $existing->isGranted()) {
                return $existing;
            }

            // Withdrawing AI memory deletes what was remembered.
            //
            // The alternative — keeping it dormant — leaves FynnEdge holding
            // personal context the customer has just said it may not hold,
            // relying on every future code path to remember not to read it.
            // The consent ledger keeps the record of the decision; the
            // memories themselves go.
            if ($type === ConsentType::AiMemory) {
                $user->aiMemories()->delete();
            }

            // Withdrawing the bureau consent deletes the credit reports
            // obtained under it, for the same reason.
            //
            // FynnEdge asked a bureau about this customer on a permission
            // that no longer stands, and there is no other purpose it could
            // honestly claim for keeping the answer. Retaining it dormant
            // would leave FynnEdge holding credit information the customer
            // has just said it may not hold.
            //
            // The ledger keeps the record that consent was granted and later
            // withdrawn — that has to survive. The reports do not.
            if ($type === ConsentType::CreditBureauCheck) {
                $user->creditReports()->delete();
            }

            // Same reasoning for documents. FynnEdge sent a document to a
            // provider on a permission that no longer stands, so the reading
            // it got back goes.
            //
            // What stays is anything the customer already confirmed into
            // their own financial profile. That is their data, entered by
            // them, and quietly changing figures they stand behind would be
            // a worse breach of the same principle.
            if ($type === ConsentType::DocumentProcessing) {
                $user->documentScans()->delete();
            }

            // And the same for verifications. What stays is the customer's
            // own profile — if they earlier chose to correct their name
            // after a difference, that is their data now, and silently
            // reverting it would breach the same principle more seriously
            // than keeping it.
            if ($type === ConsentType::IdentityVerification) {
                $user->kycVerifications()->delete();
            }

            // A new row against the version that was actually granted, so
            // the pair reads as one decision reversed rather than two
            // unrelated entries.
            return $user->consents()->create([
                'type' => $type->value,
                'version' => $existing->version,
                'status' => ConsentStatus::Withdrawn->value,
                'withdrawn_at' => now(),
                'source' => $source,
            ]);
        });
    }

    /**
     * True when the customer has granted this type under the version that is
     * currently in force.
     *
     * A consent granted under older wording does not carry forward: the
     * customer agreed to something that has since changed.
     */
    public function hasCurrentConsent(User $user, ConsentType $type): bool
    {
        $existing = $this->currentFor($user, $type);

        return $existing !== null
            && $existing->isGranted()
            && $existing->version === $type->currentVersion();
    }

    public function currentFor(User $user, ConsentType $type): ?Consent
    {
        return $user->consents()
            ->where('type', $type->value)
            ->orderByDesc('id')
            ->first();
    }
}
