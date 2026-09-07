<?php

namespace App\Domain\Scan;

/**
 * Provider vocabulary in, FynnEdge vocabulary out.
 *
 * The only place a provider's field names, confidence scale and layout are
 * known. Everything above this line — the scan service, the API, the app —
 * sees canonical fields or nothing.
 */
interface DocumentNormalizer
{
    public function family(): DocumentFamily;

    /**
     * @param  array<string, mixed>  $response
     *
     * @throws DocumentNormalizationFailed
     */
    public function normalize(array $response): CanonicalExtraction;
}
