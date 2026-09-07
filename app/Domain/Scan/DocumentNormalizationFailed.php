<?php

namespace App\Domain\Scan;

use RuntimeException;

/**
 * The provider answered, and FynnEdge could not read the answer.
 *
 * Refusing is the point. A response missing its schema, its confidence
 * semantics or its field structure could be coerced into something
 * plausible, and the result would be a number on a customer's screen that
 * no provider actually reported.
 */
class DocumentNormalizationFailed extends RuntimeException {}
