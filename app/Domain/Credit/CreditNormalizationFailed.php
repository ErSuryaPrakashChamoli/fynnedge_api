<?php

namespace App\Domain\Credit;

use RuntimeException;

/**
 * A bureau response that cannot be read unambiguously.
 *
 * Always a refusal. A score whose scale is unclear, or a model nobody
 * recognises, is not something to interpret — a number on the wrong scale
 * compared against a documented minimum produces a confident, wrong answer
 * about a customer's credit.
 */
class CreditNormalizationFailed extends RuntimeException {}
