<?php

namespace App\Domain\Kyc;

/**
 * Why FynnEdge does not do Aadhaar authentication or e-KYC.
 *
 * This class holds no logic. It exists so the decision is written down in
 * the codebase rather than only in a report, because the mistake it prevents
 * is an easy one to make later: UIDAI publishes authentication and e-KYC
 * interfaces and developer material, and it is tempting to read the
 * existence of documentation as permission to call it.
 *
 * It is not. Aadhaar authentication is a regulated operation performed by
 * authorised requesting entities, with defined AUA / KUA / ASA roles and
 * explicit resident authorisation. FynnEdge holds none of those roles and
 * has no such authorisation.
 *
 * Therefore, and until a legal review says otherwise:
 *
 *   - No FynnEdge code calls a UIDAI endpoint, production or sandbox.
 *   - No Flutter screen talks to UIDAI.
 *   - A developer sandbox is never treated as production authorisation.
 *   - No Aadhaar number is stored, logged, sent onward, put into analytics,
 *     shown to a customer, or given to FynnAI — not even masked.
 *   - The fixture provider returns an Aadhaar number precisely so the
 *     normalizer can be tested discarding it.
 *
 * A future integration goes through an authorised partner that holds the
 * ecosystem role, arriving at FynnEdge as an ordinary
 * KycVerificationProvider. If that partner returns a token or reference
 * designed so the number need not be retained, FynnEdge keeps the reference
 * and nothing else.
 *
 * The production checklist for that day lives in the Module 25 report and in
 * .env.example. It is not a to-do list; it is a set of preconditions.
 */
final class AadhaarBoundary
{
    /** The reason a customer is given if they ask for Aadhaar verification. */
    public const CUSTOMER_MESSAGE = 'FynnEdge does not offer Aadhaar-based '
        .'verification. That requires an authorisation FynnEdge does not '
        .'hold.';

    private function __construct() {}
}
