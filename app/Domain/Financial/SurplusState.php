<?php

namespace App\Domain\Financial;

/**
 * Where the customer's recorded income sits against their recorded outgo.
 *
 * Three states and an honest fourth. Nothing here is a judgement: a
 * negative surplus is a description of two figures FynnEdge was given, not
 * a verdict on somebody's life, and in practice its commonest cause is a
 * figure that moved without the profile being updated.
 */
enum SurplusState: string
{
    case Positive = 'positive';
    case Matched = 'matched';
    case Negative = 'negative';

    /** No income recorded, so the question has no answer. */
    case Unknown = 'unknown';
}
