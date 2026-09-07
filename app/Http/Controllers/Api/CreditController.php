<?php

namespace App\Http\Controllers\Api;

use App\Domain\Credit\CreditBureauUnavailable;
use App\Domain\Credit\CreditReportStatus;
use App\Http\Controllers\Controller;
use App\Services\Credit\CreditBureauNotConfigured;
use App\Services\Credit\CreditBureauService;
use App\Services\Credit\CreditConsentRequired;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's own credit information.
 *
 * Two endpoints. Reading the status never asks a bureau anything — a pull
 * may cost money and be recorded as an enquiry against the customer, so it
 * happens only when they ask for one.
 *
 * Nothing here returns a provider's response, a provider's error, or a
 * provider's reference. What a customer gets is a score, the scale it sits
 * on, whose it is, and when it was obtained.
 */
class CreditController extends Controller
{
    public function __construct(private readonly CreditBureauService $credit) {}

    /**
     * Where this customer stands, without asking a bureau anything.
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();
        $status = $this->credit->status($user);

        return response()->json([
            'data' => [
                'status' => $status->value,

                // Present only when a report exists. Never a zero standing in
                // for "we do not know" — a nought would be read as a score.
                'profile' => $status->hasReport()
                    ? $this->credit->profile($user)?->toArray()
                    : null,

                // What FynnEdge holds is a bureau's view of credit history.
                // It is not the FynnScore, which is FynnEdge's own view of
                // financial health from figures the customer entered.
                'is_fynn_score' => false,
            ],
        ]);
    }

    /**
     * Asks the bureau, if the customer has permitted it.
     *
     * Returns a report already held when one is recent enough, rather than
     * paying for and recording another enquiry.
     */
    public function check(Request $request): JsonResponse
    {
        try {
            $profile = $this->credit->check($request->user());
        } catch (CreditBureauNotConfigured) {
            return response()->json([
                'message' => 'Credit checking is not available yet.',
                'reason' => 'not_configured',
            ], Response::HTTP_NOT_IMPLEMENTED);
        } catch (CreditConsentRequired) {
            return response()->json([
                'message' => 'Allow a credit check before FynnEdge can request one.',
                'reason' => 'consent_required',
            ], Response::HTTP_FORBIDDEN);
        } catch (CreditBureauUnavailable) {
            // The bureau's own diagnosis stays server-side. A customer gets
            // what happened, not a fault code.
            return response()->json([
                'message' => 'We could not retrieve your credit information right now.',
                'reason' => 'bureau_unavailable',
            ], Response::HTTP_BAD_GATEWAY);
        }

        return response()->json([
            'data' => [
                'status' => CreditReportStatus::Available->value,
                'profile' => $profile->toArray(),
                'is_fynn_score' => false,
            ],
        ]);
    }
}
