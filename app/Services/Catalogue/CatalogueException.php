<?php

namespace App\Services\Catalogue;

use RuntimeException;

/**
 * A catalogue operation that was refused.
 *
 * The message is for whoever is administering the catalogue and says what
 * to do instead. It never reaches a customer — no customer-facing route
 * calls any of these services.
 */
class CatalogueException extends RuntimeException {}
