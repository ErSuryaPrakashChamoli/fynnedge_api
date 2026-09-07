<?php

namespace App\Domain\Kyc;

use RuntimeException;

/**
 * The provider answered, and FynnEdge could not read the answer.
 *
 * Refusing is the point. A response whose outcome semantics are missing
 * could be coerced into something plausible, and the plausible reading of a
 * verification response is "verified" — which is the one word that must
 * never appear without a provider actually having said it.
 */
class VerificationNormalizationFailed extends RuntimeException {}
