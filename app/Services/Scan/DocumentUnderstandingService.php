<?php

namespace App\Services\Scan;

use App\Domain\Consent\ConsentType;
use App\Domain\Scan\CanonicalExtraction;
use App\Domain\Scan\DocumentFamily;
use App\Domain\Scan\DocumentNormalizationFailed;
use App\Domain\Scan\DocumentNormalizer;
use App\Domain\Scan\DocumentUnderstandingProvider;
use App\Domain\Scan\DocumentUnderstandingUnavailable;
use App\Domain\Scan\ExtractedField;
use App\Domain\Scan\ScanStatus;
use App\Domain\Vault\ContentDigest;
use App\Jobs\ProcessDocumentScan;
use App\Models\Document;
use App\Models\DocumentScan;
use App\Models\User;
use App\Services\ConsentService;
use App\Services\Notifications\CustomerNotifications;
use App\Services\Vault\ExternalTransferGate;
use App\Services\Vault\ExternalTransferRefused;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Reading a document, and everything that has to be true before one is read.
 *
 * Four rules shape this class.
 *
 * Consent first, always. A document sitting in the vault is not permission
 * to send it anywhere. Consent is checked before the provider is called and
 * again after it answers, so a customer who withdraws mid-flight does not
 * have a reading of their document stored.
 *
 * Nothing is invented on failure. A provider that errors, times out or
 * returns something unreadable produces a failed scan and no fields. There
 * is no partial extraction, no empty-but-valid result, and no reuse of an
 * older reading as though it described this attempt.
 *
 * An extraction belongs to a file. The bytes are digested at scan time, so
 * a reading can always be checked against what it was actually taken from.
 *
 * And an extraction is never the customer's data. This class writes what the
 * document appears to contain. What the customer's income *is* changes only
 * through ExtractionReviewService, and only because they said so.
 */
class DocumentUnderstandingService
{
    public function __construct(
        private readonly ConsentService $consents,
        private readonly DocumentUnderstandingProvider $provider,
        private readonly DocumentNormalizer $normalizer,
        private readonly ExternalTransferGate $transfers,
        private readonly ContentDigest $digests,
        private readonly CustomerNotifications $notifications,
    ) {}

    public function isConfigured(): bool
    {
        return config('fynnedge.scan.driver', 'none') !== 'none'
            && $this->provider->isAvailable();
    }

    public function hasConsent(User $user): bool
    {
        return $this->consents->hasCurrentConsent($user, ConsentType::DocumentProcessing);
    }

    /**
     * What FynnEdge knows about this document, without asking anybody.
     *
     * Safe on any screen: it never causes a provider call, so opening
     * FynnScan can never cost the customer a charge.
     */
    public function statusFor(Document $document): DocumentScanState
    {
        $family = DocumentFamily::forType($document->type());

        if (! $this->isConfigured()) {
            return DocumentScanState::unavailable($document);
        }

        if ($family === null || ! $this->provider->supports($family)) {
            return DocumentScanState::unsupported($document);
        }

        $scan = $this->latestFor($document);

        if ($scan === null) {
            return $this->hasConsent($document->user)
                ? DocumentScanState::notStarted($document)
                : DocumentScanState::consentRequired($document);
        }

        return DocumentScanState::of($document, $scan, $this->isStale($scan, $document));
    }

    /**
     * Asks for a document to be read.
     *
     * @throws ScanNotConfigured|ScanConsentRequired|ScanUnsupportedDocument
     * @throws ScanAlreadyRunning
     */
    public function scan(
        Document $document,
        bool $force = false,
    ): DocumentScanState {
        if (! $this->isConfigured()) {
            throw new ScanNotConfigured;
        }

        $family = DocumentFamily::forType($document->type());

        if ($family === null || ! $this->provider->supports($family)) {
            throw new ScanUnsupportedDocument;
        }

        // Checked immediately before the file moves, not earlier: a customer
        // who withdraws while a screen is open must not have their document
        // sent anyway.
        if (! $this->hasConsent($document->user)) {
            throw new ScanConsentRequired;
        }

        $existing = $this->latestFor($document);

        if ($existing !== null && $existing->isInFlight()) {
            // Three taps are one reading. A provider call already in flight
            // is the answer to all of them.
            throw new ScanAlreadyRunning;
        }

        if (
            $existing !== null
            && ! $force
            && $existing->status()->hasExtraction()
            && ! $this->isStale($existing, $document)
        ) {
            // A reading of these exact bytes already exists. Showing it
            // again costs nothing; asking the provider again costs money.
            return DocumentScanState::of($document, $existing, false);
        }

        $bytes = $this->readBytes($document);

        /*
         * Nothing leaves FynnEdge unchecked.
         *
         * Only availability is checked here — cheap, and enough to refuse
         * immediately rather than queue work that would fail out of sight.
         * The file itself is scanned once, by the worker that actually
         * sends it, because those are the bytes that leave.
         */
        $this->transfers->assertDestinationReachable(
            $document,
            $this->provider->isSample(),
            'document_understanding',
        );

        $scan = DocumentScan::create([
            'user_id' => $document->user_id,
            'document_id' => $document->getKey(),
            'provider' => $this->provider->name(),
            'is_sample' => $this->provider->isSample(),

            // Queued, not processing: nothing has been asked of a provider
            // yet, and a status claiming otherwise would be the product
            // saying work had started before it had.
            'status' => ScanStatus::Queued->value,

            'family' => $family->value,
            'schema_version' => $family->schemaVersion(),
            'file_digest' => hash('sha256', $bytes),
        ]);

        $this->log('document_scan_queued', $scan);

        /*
         * Handed to the queue rather than done here.
         *
         * A document-understanding provider can take a long time on a large
         * file, and a customer's request must not be held open for it. On
         * the sync connection the worker runs inline and this returns the
         * finished state; on a real queue it returns "queued" and the
         * customer's screen shows that truthfully.
         */
        ProcessDocumentScan::dispatch($scan->getKey());

        return $this->statusFor($document->fresh());
    }

    /**
     * Does the reading. Called by the worker, never from a request.
     *
     * Everything it depends on is checked again here, because time has
     * passed since the customer asked: the document may have been deleted,
     * its file replaced, or consent withdrawn. A worker that trusted the
     * state at enqueue time would send a document somebody had since said
     * it must not send.
     */
    public function process(DocumentScan $scan): void
    {
        $document = $scan->document;

        if ($document === null || ! $document->exists()) {
            $this->failScan($scan, 'document_missing', 'load');

            return;
        }

        $family = DocumentFamily::tryFrom($scan->family);

        if ($family === null || ! $this->provider->supports($family)) {
            $this->failScan(
                $scan,
                DocumentUnderstandingUnavailable::UNSUPPORTED_DOCUMENT,
                'load',
            );

            return;
        }

        // Consent again, immediately before the file moves. A customer who
        // withdrew while this sat in the queue must not have their document
        // sent anyway.
        if (! $this->hasConsent($document->user)) {
            $this->discard($scan);

            return;
        }

        try {
            $bytes = $this->readBytes($document);
        } catch (RuntimeException) {
            $this->failScan($scan, 'document_missing', 'load');

            return;
        }

        if (hash('sha256', $bytes) !== $scan->file_digest) {
            /*
             * The file changed while this was queued.
             *
             * The customer asked for one file to be read and a different
             * one is now stored. Reading it would answer a question they
             * did not ask, so this stops and they can ask again.
             */
            $this->failScan($scan, 'document_changed', 'load');

            return;
        }

        // The authoritative check: these are the bytes that leave.
        try {
            $this->transfers->assertMayLeave(
                $document,
                $bytes,
                $this->provider->isSample(),
                'document_understanding',
            );
        } catch (ExternalTransferRefused $e) {
            // The gate's own reason, not a generic one: a customer whose
            // file did not pass a safety check needs to be told that, and
            // one refused because FynnEdge has no scanner needs to be told
            // something quite different.
            $this->failScan($scan, $e->reason(), 'gate');

            /*
             * And only the first of those is a notification.
             *
             * A file that failed a safety check is the customer's problem
             * to solve — they need to add a different one. A refusal
             * because FynnEdge has no scanner configured is FynnEdge's
             * problem, and telling a customer their document "could not be
             * accepted" for it would blame them for an operational gap.
             */
            if ($e->reason() === 'document_failed_safety_check') {
                $this->notifications->documentSafetyIssue($document);
            }

            return;
        }

        $scan->update(['status' => ScanStatus::Processing->value, 'started_at' => now()]);
        $this->log('document_scan_requested', $scan);

        try {
            $response = $this->provider->process($document, $bytes, $family);
            $extraction = $this->normalizer->normalize($response);
        } catch (DocumentNormalizationFailed) {
            // A response FynnEdge cannot read is a response it does not act
            // on. The reason stays server-side.
            $this->failScan(
                $scan,
                DocumentUnderstandingUnavailable::UNREADABLE_DOCUMENT,
                'normalize',
            );

            return;
        } catch (DocumentUnderstandingUnavailable $e) {
            $this->failScan($scan, $e->reason, 'process');

            return;
        }

        // Consent is re-checked after the answer comes back as well. The
        // document has already left by then, but a customer who withdrew
        // while it was in flight does not get a reading of it stored.
        if (! $this->hasConsent($document->user->fresh())) {
            $this->discard($scan);

            return;
        }

        $this->store($scan, $extraction);
        $this->log('document_scan_completed', $scan);
    }

    private function discard(DocumentScan $scan): void
    {
        $this->log('document_scan_discarded', $scan);
        $scan->delete();
    }

    private function failScan(DocumentScan $scan, string $reason, string $stage): void
    {
        $scan->update([
            'status' => ScanStatus::Failed->value,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ]);

        $this->log('document_scan_failed', $scan->fresh(), ['stage' => $stage]);
    }

    /** The most recent attempt, whatever became of it. */
    public function latestFor(Document $document): ?DocumentScan
    {
        return DocumentScan::query()
            ->where('document_id', $document->getKey())
            ->with(['fields', 'reviews'])
            ->latest('id')
            ->first();
    }

    /**
     * Removes every reading FynnEdge holds for this customer.
     *
     * Called when processing consent is withdrawn. The extractions go
     * entirely rather than being retained-but-unused: FynnEdge obtained them
     * on a permission that no longer stands.
     *
     * What survives is what the customer already confirmed into their own
     * financial profile. That is their data now, entered by them, and
     * deleting it would silently change figures they stand behind.
     */
    public function forget(User $user): int
    {
        $scans = DocumentScan::where('user_id', $user->getKey())->get();

        foreach ($scans as $scan) {
            $scan->delete();
        }

        return $scans->count();
    }

    /**
     * Whether a reading still describes the file behind the document.
     *
     * An extraction is bound to bytes, not to a document type. If the file
     * ever differs from what was read, the reading is stale and is shown as
     * belonging to a previous file rather than to this one.
     */
    public function isStale(DocumentScan $scan, Document $document): bool
    {
        return ! $this->digests->matches($document, $scan->file_digest);
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
        DocumentScan $scan,
        Document $document,
        string $reason,
        string $stage,
    ): DocumentScanState {
        $scan->update([
            'status' => ScanStatus::Failed->value,
            'failure_reason' => $reason,
            'completed_at' => now(),
        ]);

        $this->log('document_scan_failed', $scan, ['stage' => $stage]);

        return DocumentScanState::of($document, $scan->fresh(), false);
    }

    /**
     * Writes the reading.
     *
     * Completed only when every field was read cleanly. Anything partial,
     * uncertain, ambiguous or misclassified is needs_review, because a
     * customer shown "read" over a half-read document would reasonably
     * assume the other half was fine.
     */
    private function store(DocumentScan $scan, CanonicalExtraction $extraction): void
    {
        $family = DocumentFamily::from($scan->family);
        $mismatch = $extraction->disagreesWith($family);

        DB::transaction(function () use ($scan, $extraction, $mismatch) {
            foreach ($extraction->fields as $field) {
                $scan->fields()->create($this->fieldRow($field));
            }

            $complete = $extraction->isComplete() && ! $mismatch;

            $scan->update([
                'status' => $complete
                    ? ScanStatus::Completed->value
                    : ScanStatus::NeedsReview->value,
                'classified_as' => $extraction->classifiedAs,
                'document_period' => $extraction->documentPeriod,
                'model_version' => $extraction->modelVersion,
                'completed_at' => now(),
            ]);

            /*
             * A reading finished, so there is something for the customer to
             * look at — and two different things to say about it.
             *
             * Complete: the reading is ready to review. Nothing is claimed
             * about what it found, and nothing extracted travels: a salary
             * figure in a notification is a salary figure on a lock screen.
             *
             * Needs review: something in it could not be trusted, which is
             * a job for them rather than an announcement.
             */
            $document = $scan->document;

            if ($document !== null) {
                $complete
                    ? $this->notifications->documentReady($document)
                    : $this->notifications->documentNeedsReview($document);
            }
        });
    }

    /** @return array<string, mixed> */
    private function fieldRow(ExtractedField $field): array
    {
        $evidence = $field->evidence;

        return [
            'key' => $field->key,
            'label' => $field->label,
            'value' => $field->value,
            'value_type' => $field->valueType,
            'amount_paise' => $field->paise,
            'confidence' => $field->confidence->value,
            'needs_review' => $field->needsReview(),
            'review_reason' => $field->reviewReason,
            'evidence_page' => $evidence?->page,
            'evidence_section' => $evidence?->section,
            'evidence_snippet' => $evidence?->snippet,
            'evidence_box' => $evidence?->box,
        ];
    }

    /**
     * Operational facts only.
     *
     * Never a document's bytes, never its text, never an extracted value,
     * never a name, PAN, account number or address. What is here is what an
     * operator needs to see that the pipeline is working: which scan, which
     * document, which provider, what state.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, DocumentScan $scan, array $context = []): void
    {
        Log::info($event, array_merge([
            'scan_id' => $scan->getKey(),
            'document_id' => $scan->document_id,
            'user_id' => $scan->user_id,
            'provider' => $scan->provider,
            'status' => $scan->status,
        ], $context));
    }
}

/** No document-understanding provider is configured. */
class ScanNotConfigured extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No document-understanding provider is configured.');
    }
}

/** The customer has not permitted document processing. */
class ScanConsentRequired extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Document processing has not been permitted.');
    }
}

/** FynnScan does not read this kind of document. */
class ScanUnsupportedDocument extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('FynnScan does not read this kind of document.');
    }
}

/** A reading of this document is already under way. */
class ScanAlreadyRunning extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('This document is already being read.');
    }
}
