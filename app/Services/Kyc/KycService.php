<?php

namespace App\Services\Kyc;

use App\Domain\Consent\ConsentType;
use App\Domain\Kyc\CanonicalVerification;
use App\Domain\Kyc\KycNormalizer;
use App\Domain\Kyc\KycVerificationProvider;
use App\Domain\Kyc\VerificationNormalizationFailed;
use App\Domain\Kyc\VerificationStatus;
use App\Domain\Kyc\VerificationType;
use App\Domain\Kyc\VerificationUnavailable;
use App\Domain\Vault\ContentDigest;
use App\Models\Document;
use App\Models\KycVerification;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Vault\ExternalTransferGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Verifying a document, and everything that has to be true before one is.
 *
 * Four rules shape this class.
 *
 * Consent first, always, and specifically. Permission to have a document
 * *read* is not permission to have an identity *checked*; this service
 * requires its own consent, checked before the document moves and again
 * after the provider answers.
 *
 * Nothing is concluded on failure. A provider that errors, times out or
 * returns something unreadable produces a failed verification and no
 * findings. There is no partial result, no empty-but-valid pass, and no
 * reuse of an older result as though it described this attempt.
 *
 * A result belongs to a file. The bytes are digested at verification time,
 * so a result can always be checked against what it actually described.
 *
 * And a verification is never the customer's profile. This class writes what
 * a service established about a document. What the customer's name *is*
 * changes only through their own explicit act, in IdentityResolutionService.
 */
class KycService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly KycVerificationProvider $provider,
        private readonly KycNormalizer $normalizer,
        private readonly ExternalTransferGate $transfers,
        private readonly ContentDigest $digests,
    ) {}

    public function isConfigured(): bool
    {
        return config('fynnedge.kyc.driver', 'none') !== 'none'
            && $this->provider->isAvailable();
    }

    public function hasConsent(User $user): bool
    {
        return $this->consents->hasCurrentConsent(
            $user,
            ConsentType::IdentityVerification,
        );
    }

    /**
     * What FynnEdge knows about this document, without asking anybody.
     *
     * Safe on any screen: it never causes a provider call, so opening a
     * screen can never cost the customer a charge.
     */
    public function statusFor(Document $document): KycVerificationState
    {
        $type = VerificationType::forType($document->type());

        if (! $this->isConfigured()) {
            return KycVerificationState::unavailable($document);
        }

        if ($type === null || ! $this->provider->supports($type)) {
            return KycVerificationState::unsupported($document);
        }

        $verification = $this->latestFor($document);

        if ($verification === null) {
            return $this->hasConsent($document->user)
                ? KycVerificationState::notStarted($document)
                : KycVerificationState::consentRequired($document);
        }

        return KycVerificationState::of(
            $document,
            $verification,
            $this->isStale($verification, $document),
        );
    }

    /**
     * Asks for a document to be verified.
     *
     * @throws KycNotConfigured|KycConsentRequired|KycUnsupportedDocument
     * @throws KycAlreadyRunning
     */
    public function verify(Document $document, bool $force = false): KycVerificationState
    {
        if (! $this->isConfigured()) {
            throw new KycNotConfigured;
        }

        $type = VerificationType::forType($document->type());

        if ($type === null || ! $this->provider->supports($type)) {
            throw new KycUnsupportedDocument;
        }

        // Checked immediately before the file moves, not earlier: a customer
        // who withdraws while a screen is open must not have their identity
        // document sent anyway.
        if (! $this->hasConsent($document->user)) {
            throw new KycConsentRequired;
        }

        $existing = $this->latestFor($document);

        if ($existing !== null && $existing->isInFlight()) {
            // Two taps are one check. A provider call already in flight is
            // the answer to both.
            throw new KycAlreadyRunning;
        }

        if (
            $existing !== null
            && ! $force
            && $existing->status()->hasResult()
            && ! $this->isStale($existing, $document)
        ) {
            /*
             * A result for these exact bytes already exists.
             *
             * It is returned rather than re-obtained. This is not a reuse
             * window — FynnEdge does not invent one, because how long a
             * verification stays current is a provider and policy question
             * nobody has answered. It is simply that asking the same
             * question about the same file again costs money and learns
             * nothing. Re-verifying is always available explicitly.
             */
            return KycVerificationState::of($document, $existing, false);
        }

        $bytes = $this->readBytes($document);

        // The same gate as FynnScan's. An identity document is the last
        // file that should reach an unvetted external service.
        //
        // Verification is still done in-request: unlike a document reading,
        // it is a short call and its result is what the customer is waiting
        // on. So the full check runs here, once.
        $this->transfers->assertMayLeave(
            $document,
            $bytes,
            $this->provider->isSample(),
            'identity_verification',
        );

        $verification = KycVerification::create([
            'user_id' => $document->user_id,
            'document_id' => $document->getKey(),
            'provider' => $this->provider->name(),
            'is_sample' => $this->provider->isSample(),
            'verification_type' => $type->value,
            'status' => VerificationStatus::Processing->value,
            'file_digest' => hash('sha256', $bytes),
        ]);

        $this->log('kyc_requested', $verification);

        try {
            $response = $this->provider->verify($document, $bytes, $type);
            $result = $this->normalizer->normalize(
                $response,
                $document->user->customerProfile,
            );
        } catch (VerificationNormalizationFailed) {
            // A response FynnEdge cannot read is a response it does not act
            // on. The reason stays server-side.
            return $this->fail(
                $verification,
                $document,
                VerificationUnavailable::UNREADABLE_DOCUMENT,
                'normalize',
            );
        } catch (VerificationUnavailable $e) {
            return $this->fail($verification, $document, $e->reason, 'verify');
        }

        // Consent is re-checked after the answer comes back as well. The
        // document has already left by then, but a customer who withdrew
        // while it was in flight does not get a verification of it stored,
        // and is never shown a result obtained after they said stop.
        if (! $this->hasConsent($document->user->fresh())) {
            $this->log('kyc_discarded', $verification);
            $verification->delete();

            throw new KycConsentRequired;
        }

        $this->store($verification, $result);
        $this->log('kyc_completed', $verification->fresh());

        return KycVerificationState::of(
            $document,
            $verification->fresh(['matches']),
            false,
        );
    }

    public function latestFor(Document $document): ?KycVerification
    {
        return KycVerification::query()
            ->where('document_id', $document->getKey())
            ->with('matches')
            ->latest('id')
            ->first();
    }

    /**
     * Removes every verification FynnEdge holds for this customer.
     *
     * Called when identity-verification consent is withdrawn. The results go
     * entirely rather than being retained-but-unused: FynnEdge obtained them
     * on a permission that no longer stands.
     *
     * What survives is the customer's own profile. If they earlier chose to
     * correct their name after a mismatch, that is their data, entered by
     * them, and silently reverting it would be a worse breach of the same
     * principle. The consent ledger survives too — it is append-only, and
     * that a customer granted and later withdrew has to remain answerable.
     */
    public function forget(User $user): int
    {
        $verifications = KycVerification::where('user_id', $user->getKey())->get();

        foreach ($verifications as $verification) {
            $verification->delete();
        }

        return $verifications->count();
    }

    /**
     * Whether a result still describes the file behind the document.
     */
    public function isStale(KycVerification $verification, Document $document): bool
    {
        return ! $this->digests->matches($document, $verification->file_digest);
    }

    /**
     * The bytes, read server-side from private storage.
     *
     * The only path a document takes to a provider. No URL is generated, no
     * storage key is exposed, and nothing about where the file lives leaves
     * this method.
     */
    private function readBytes(Document $document): string
    {
        if (! $document->exists()) {
            throw new RuntimeException('document_missing');
        }

        $bytes = $document->disk()->get($document->storage_key);

        if ($bytes === null || $bytes === '') {
            throw new RuntimeException('document_missing');
        }

        return $bytes;
    }

    private function fail(
        KycVerification $verification,
        Document $document,
        string $reason,
        string $stage,
    ): KycVerificationState {
        $verification->update([
            'status' => VerificationStatus::Failed->value,
            'failure_reason' => $reason,
            'checked_at' => now(),
        ]);

        $this->log('kyc_failed', $verification, ['stage' => $stage]);

        return KycVerificationState::of($document, $verification->fresh(), false);
    }

    private function store(
        KycVerification $verification,
        CanonicalVerification $result,
    ): void {
        DB::transaction(function () use ($verification, $result) {
            foreach ($result->fieldMatches as $match) {
                $verification->matches()->create([
                    'field' => $match->field,
                    'label' => $match->label,
                    'status' => $match->status->value,
                    'reason' => $match->reason,

                    // Stored only where there is a difference to resolve.
                    'profile_value' => $match->profileValue,
                    'verified_value' => $match->verifiedValue,
                ]);
            }

            $verification->update([
                'status' => $result->status()->value,
                'document_result' => $result->documentResult->value,
                'identity_result' => $result->identityResult->value,
                'masked_reference' => $result->maskedReference,
                'provider_reference' => $result->providerReference,
                'checked_at' => $result->checkedAt,
                'expires_at' => $result->expiresAt,
            ]);
        });
    }

    /**
     * Operational facts only.
     *
     * Never a name, a document number, an Aadhaar number, a date of birth,
     * an address, the document's bytes or the provider's response. What is
     * here is what an operator needs to see the pipeline working: which
     * verification, which document, which provider, what state.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, KycVerification $verification, array $context = []): void
    {
        Log::info($event, array_merge([
            'verification_id' => $verification->getKey(),
            'document_id' => $verification->document_id,
            'user_id' => $verification->user_id,
            'provider' => $verification->provider,
            'status' => $verification->status,
        ], $context));
    }
}

/** No verification provider is configured. */
class KycNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No verification provider is configured.');
    }
}

/** The customer has not permitted identity verification. */
class KycConsentRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Identity verification has not been permitted.');
    }
}

/** FynnEdge does not verify this kind of document. */
class KycUnsupportedDocument extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('FynnEdge does not verify this kind of document.');
    }
}

/** A verification of this document is already under way. */
class KycAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This document is already being checked.');
    }
}
