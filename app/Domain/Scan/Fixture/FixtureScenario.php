<?php

namespace App\Domain\Scan\Fixture;

/**
 * What the fictional provider does when asked.
 *
 * Chosen by server-side configuration and by tests. Deliberately not
 * selectable from a request: a customer able to pick `Good` would be
 * choosing their own extraction, and one able to pick a provider at all
 * would be choosing whether their reading is labelled as sample data.
 */
enum FixtureScenario: string
{
    /** Every field read cleanly. */
    case Good = 'good';

    /** One field the provider was unsure about. */
    case LowConfidence = 'low_confidence';

    /** A field the document does not carry. */
    case MissingField = 'missing_field';

    /** Two candidate values, with nothing to choose between them. */
    case ConflictingField = 'conflicting_field';

    /** An amount written in a notation no contract defines. */
    case AmbiguousAmount = 'ambiguous_amount';

    /** A date that could be two different months. */
    case AmbiguousDate = 'ambiguous_date';

    /** The provider says this is not the kind of document it was filed as. */
    case Misclassified = 'misclassified';

    /** A response FynnEdge cannot make sense of. */
    case Unreadable = 'unreadable';

    /** The provider could not read the file. */
    case UnreadableDocument = 'unreadable_document';

    /** The provider does not handle this document family. */
    case UnsupportedDocument = 'unsupported_document';

    /** The provider is down. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider did not answer in time. */
    case ProviderTimeout = 'provider_timeout';
}
