<?php

namespace App\Jobs;

use App\Models\DocumentScan;
use App\Services\Scan\DocumentUnderstandingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Reads one document, away from the customer's request.
 *
 * Carries an id and nothing else. A job payload is written to the queue
 * store and sits there until a worker picks it up, so putting a document's
 * bytes — or anything read out of them — in one would be keeping a copy of
 * a customer's file somewhere nobody accounted for.
 *
 * It does not retry. A provider that refused, a file that changed and a
 * document that was deleted are all states a retry would not improve, and
 * an automatic second attempt at a billable provider call is a cost the
 * customer did not ask for. Failures are recorded on the scan for the
 * customer to see and act on.
 */
class ProcessDocumentScan implements ShouldQueue
{
    use Queueable;

    /** One attempt. See the class note. */
    public int $tries = 1;

    public function __construct(public readonly int $scanId) {}

    public function handle(DocumentUnderstandingService $understanding): void
    {
        $scan = DocumentScan::with('document')->find($this->scanId);

        if ($scan === null) {
            // Deleted between enqueue and here — a customer withdrawing
            // consent removes their scans. Nothing to do, and nothing to
            // report.
            return;
        }

        $understanding->process($scan);
    }
}
