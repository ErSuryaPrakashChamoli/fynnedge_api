<?php

namespace App\Domain\Vault;

use App\Models\Document;
use Illuminate\Contracts\Cache\Repository as Cache;
use Throwable;

/**
 * The SHA-256 of the bytes behind a document.
 *
 * Several parts of FynnEdge bind a record to a file rather than to a
 * document row: an extraction, a KYC result and a document confirmation
 * each store the digest of what they described, so that a file which later
 * differs is reported as a different file instead of silently inheriting
 * the old answer. Those checks run on read paths — opening an application,
 * looking at a scan — and computing them naively means reading every
 * attached file in full on every request. A home loan with five confirmed
 * 9 MB documents measured 215 ms per checklist against 15 ms for the same
 * route with nothing attached: 45 MB read and hashed to render a list.
 *
 * So the digest is computed once per distinct file state and remembered.
 * The cache key carries the file's own size and modification time, which
 * costs a stat rather than a read, and a file that differs in either is
 * hashed again. What this detects is therefore unchanged for every way a
 * file can ordinarily change; what it no longer re-proves on every read is
 * a rewrite that preserves both the byte count and the timestamp exactly,
 * which takes deliberate effort and which a stored digest cannot survive
 * being trusted about anyway. Anything that actually reads the bytes —
 * a scan, a verification, a transfer out — still hashes what it read.
 *
 * Nothing here is cached across a change: the key IS the file state.
 */
final class ContentDigest
{
    /**
     * Long, because an entry can only be reached again by a file whose size
     * and timestamp are identical — in which case the digest is still the
     * right answer. The lifetime bounds cache growth, not correctness.
     */
    private const TTL_SECONDS = 86400;

    public function __construct(private readonly Cache $cache) {}

    /**
     * The digest of the file behind this document, or null when there is no
     * readable file — a document whose bytes are gone has no digest rather
     * than a digest of nothing.
     */
    public function of(?Document $document): ?string
    {
        if ($document === null) {
            return null;
        }

        $state = $this->fileState($document);

        if ($state === null) {
            return null;
        }

        $key = 'vault:digest:'.$document->getKey().':'.$state;

        $cached = $this->cache->get($key);

        if (is_string($cached)) {
            return $cached;
        }

        $digest = $this->hash($document);

        if ($digest !== null) {
            $this->cache->put($key, $digest, self::TTL_SECONDS);
        }

        return $digest;
    }

    /**
     * Whether the file behind this document is still the one a record
     * described. A record with no digest, or a document with no readable
     * file, does not match: neither can be shown as agreeing.
     */
    public function matches(?Document $document, ?string $expected): bool
    {
        if ($expected === null) {
            return false;
        }

        return $this->of($document) === $expected;
    }

    /**
     * Size and modification time, read from the disk's metadata without
     * touching the contents. Null when the file is not there.
     */
    private function fileState(Document $document): ?string
    {
        try {
            $disk = $document->disk();

            if (! $disk->exists($document->storage_key)) {
                return null;
            }

            return $disk->size($document->storage_key)
                .'-'.$disk->lastModified($document->storage_key);
        } catch (Throwable) {
            // A disk that cannot describe a file cannot be assumed to still
            // hold the same one.
            return null;
        }
    }

    /**
     * Hashed in chunks, so a 10 MB document costs a stream and not 10 MB of
     * process memory held at once.
     */
    private function hash(Document $document): ?string
    {
        try {
            $stream = $document->disk()->readStream($document->storage_key);
        } catch (Throwable) {
            return null;
        }

        if (! is_resource($stream)) {
            return null;
        }

        try {
            $context = hash_init('sha256');
            hash_update_stream($context, $stream);

            return hash_final($context);
        } finally {
            fclose($stream);
        }
    }
}
