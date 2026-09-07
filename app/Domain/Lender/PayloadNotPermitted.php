<?php

namespace App\Domain\Lender;

use RuntimeException;

/**
 * A mapper offered a field FynnEdge does not send.
 *
 * An operational error and never a failure of the customer's application:
 * it is raised before any lock is taken, before an attempt row exists and
 * before the provider is called, so an application that trips this has not
 * been sent anywhere and nothing about it has left.
 *
 * The refusal is loud rather than a silent drop. A mapper offering a PAN,
 * a credit score or a document is written against a provider's field list
 * by someone who has not read SubmissionFieldMap, and quietly removing the
 * field would leave that mapper in place for the next one.
 */
class PayloadNotPermitted extends RuntimeException
{
    public function __construct(public readonly string $field, string $why)
    {
        parent::__construct(
            "The outbound payload offered [{$field}], which FynnEdge does "
            ."not send: {$why}"
        );
    }
}
