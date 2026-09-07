<?php

namespace App\Domain\Kyc\Fixture;

/**
 * What the fictional verification service does when asked.
 *
 * Chosen by server-side configuration and by tests. Deliberately not
 * selectable from a request: a customer able to pick `Verified` would be
 * issuing their own verification, which is the entire thing this module
 * exists to prevent.
 */
enum FixtureScenario: string
{
    /** Document checks pass, and the identity matches the profile. */
    case Verified = 'verified';

    /** Document checks pass, and the name differs from the profile. */
    case NameMismatch = 'name_mismatch';

    /** The provider's own document checks did not pass. */
    case DocumentNotVerified = 'document_not_verified';

    /** The provider reports tampering signals on the document. */
    case DocumentTampered = 'document_tampered';

    /** The provider ran and could not reach a definitive answer. */
    case Ambiguous = 'ambiguous';

    /** The provider returned no name to compare. */
    case NoIdentityReturned = 'no_identity_returned';

    /** A response FynnEdge cannot make sense of. */
    case Unreadable = 'unreadable';

    /** The provider could not read the file. */
    case UnreadableDocument = 'unreadable_document';

    /** The provider does not check this kind of document. */
    case UnsupportedDocument = 'unsupported_document';

    /** The provider is down. */
    case ProviderUnavailable = 'provider_unavailable';

    /** The provider did not answer in time. */
    case ProviderTimeout = 'provider_timeout';
}
