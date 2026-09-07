<?php

namespace App\Services\Vault;

use App\Domain\Vault\Malware\MalwareScanner;
use App\Domain\Vault\Malware\ScanVerdict;
use App\Models\Document;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * The check every customer file passes before it leaves FynnEdge.
 *
 * One rule: a file may go to an external party only when a scanner has
 * looked at it and found nothing. Not when a scanner is unavailable, not
 * when it could not decide, and not because the feature asking is important.
 *
 * The gate applies to real destinations only. A fixture provider sends
 * nothing anywhere — the bytes never leave this process — so requiring a
 * scanner for one would block development while protecting nobody. What
 * decides is the provider's own `isSimulated`, which is server-owned and
 * which no request can change.
 *
 * The effect today, with no scanner and no real provider, is that nothing
 * changes. The effect on the day somebody writes a real adapter is that it
 * refuses to send until a scanner exists — which is the whole point of
 * writing this before that day rather than after it.
 */
class ExternalTransferGate
{
    public function __construct(
        private readonly MalwareScanner $scanner,
    ) {}

    public function scannerIsAvailable(): bool
    {
        return $this->scanner->isAvailable();
    }

    /**
     * Whether a transfer to this kind of destination could go ahead at all.
     *
     * Deliberately cheap: it asks whether a scanner exists, and does not
     * run one. Callers use it to refuse immediately — before queuing work
     * that would only fail out of sight — while the file itself is checked
     * once, by whatever actually sends it.
     *
     * @throws ExternalTransferRefused
     */
    public function assertDestinationReachable(
        Document $document,
        bool $destinationIsSimulated,
        string $purpose,
    ): void {
        if ($destinationIsSimulated || $this->scanner->isAvailable()) {
            return;
        }

        $this->refuse($document, $purpose, ScanVerdict::NotScanned);
    }

    /**
     * Checks a file that is about to be sent to a real external party.
     *
     * @param  bool  $destinationIsSimulated  whether the receiving provider
     *                                        sends the bytes nowhere
     *
     * @throws ExternalTransferRefused
     */
    public function assertMayLeave(
        Document $document,
        string $bytes,
        bool $destinationIsSimulated,
        string $purpose,
    ): ScanVerdict {
        if ($destinationIsSimulated) {
            /*
             * Nothing leaves, so nothing is at risk.
             *
             * Recorded as not scanned rather than clean: a simulated
             * transfer is not evidence that a file is safe, and a later
             * real transfer must not be able to lean on it.
             */
            return ScanVerdict::NotScanned;
        }

        if (! $this->scanner->isAvailable()) {
            $this->refuse($document, $purpose, ScanVerdict::NotScanned);
        }

        $verdict = $this->scanner->scan($document, $bytes);

        if (! $verdict->permitsExternalTransfer()) {
            $this->refuse($document, $purpose, $verdict);
        }

        return $verdict;
    }

    /**
     * @throws ExternalTransferRefused
     */
    private function refuse(
        Document $document,
        string $purpose,
        ScanVerdict $verdict,
    ): never {
        // Operational facts only: which document, what for, what the
        // verdict was. Never the bytes, the filename the customer chose or
        // anything read out of the file.
        Log::warning('external_transfer_refused', [
            'document_id' => $document->getKey(),
            'user_id' => $document->user_id,
            'purpose' => $purpose,
            'verdict' => $verdict->value,
            'scanner' => $this->scanner->name(),
        ]);

        throw new ExternalTransferRefused($verdict);
    }
}

/**
 * A file was not sent, because nothing had established it was safe to send.
 */
class ExternalTransferRefused extends RuntimeException
{
    public function __construct(public readonly ScanVerdict $verdict)
    {
        parent::__construct('That document cannot be sent outside FynnEdge.');
    }

    /**
     * What a customer is told.
     *
     * The distinction matters: a missing scanner is a fact about FynnEdge,
     * and a threat found in their file is a fact about the file. A customer
     * told the second when the first is true has been accused of something.
     */
    public function customerMessage(): string
    {
        return match ($this->verdict) {
            ScanVerdict::Infected => 'This document did not pass a safety '
                .'check, so FynnEdge has not sent it anywhere. Please try a '
                .'different file.',
            ScanVerdict::Inconclusive => 'FynnEdge could not finish checking '
                .'this document, so it has not been sent anywhere.',
            default => 'FynnEdge cannot send documents to an external '
                .'service yet. Your document is stored safely and has not '
                .'been sent anywhere.',
        };
    }

    public function reason(): string
    {
        return match ($this->verdict) {
            ScanVerdict::Infected => 'document_failed_safety_check',
            ScanVerdict::Inconclusive => 'document_check_inconclusive',
            default => 'malware_scanning_not_configured',
        };
    }
}
