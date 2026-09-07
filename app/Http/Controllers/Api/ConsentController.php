<?php

namespace App\Http\Controllers\Api;

use App\Domain\Consent\ConsentSource;
use App\Domain\Consent\ConsentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreConsentRequest;
use App\Models\Consent;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;

/**
 * The customer's consent, always the authenticated one.
 *
 * There is no endpoint that edits or removes a record, because there is no
 * such operation: a change of mind is a new decision appended to the ledger.
 */
class ConsentController extends Controller
{
    public function __construct(private readonly ConsentService $consents) {}

    /** The catalogue, with this customer's position on each entry. */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $current = $this->consents->current($user);

        $items = [];

        foreach (ConsentType::cases() as $type) {
            $record = $current[$type->value] ?? null;
            $granted = $record !== null
                && $record->isGranted()
                && $record->version === $type->currentVersion();

            $items[] = [
                'type' => $type->value,
                'label' => $type->label(),
                'description' => $type->description(),

                'is_required' => $type->isRequired(),
                // False where the capability behind it does not exist. The
                // app shows these as not available, and grant refuses them.
                'is_available' => $type->isAvailable(),
                'unavailable_reason' => $type->unavailableReason(),

                'current_version' => $type->currentVersion(),
                'is_granted' => $granted,

                // Granted under wording that has since been replaced: the
                // customer has not agreed to what is in force now.
                'needs_reconfirmation' => $record !== null
                    && $record->isGranted()
                    && $record->version !== $type->currentVersion(),

                'status' => $record?->status,
                'granted_version' => $record?->version,
                'decided_at' => $record?->updated_at?->toIso8601String(),
            ];
        }

        return response()->json([
            'data' => [
                'consents' => $items,
                'outstanding_required' => array_values(array_map(
                    fn (array $item) => $item['type'],
                    array_filter(
                        $items,
                        fn (array $item) => $item['is_required'] && ! $item['is_granted'],
                    ),
                )),
            ],
        ]);
    }

    public function store(StoreConsentRequest $request): JsonResponse
    {
        try {
            $this->consents->grant(
                $request->user(),
                ConsentType::from($request->validated('type')),
                ConsentSource::fromInput($request->validated('source'))->value,
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->index($request);
    }

    public function withdraw(Request $request, string $type): JsonResponse
    {
        $consentType = ConsentType::tryFrom($type);

        if ($consentType === null) {
            return response()->json([
                'message' => 'That permission does not exist.',
            ], Response::HTTP_NOT_FOUND);
        }

        try {
            $this->consents->withdraw(
                $request->user(),
                $consentType,
                // Withdrawal has no FormRequest, so the value is narrowed
                // to a known surface here rather than trusted.
                ConsentSource::fromInput($request->input('source'))->value,
            );
        } catch (RuntimeException $e) {
            return $this->failure($e);
        }

        return $this->index($request);
    }

    /** Everything this customer has ever decided, newest first. */
    public function history(Request $request): JsonResponse
    {
        $history = $this->consents->history($request->user())
            ->map(function (Consent $consent) {
                $type = $consent->type();

                return [
                    // No database id: an internal key is not something the
                    // customer needs, and it is not stable vocabulary.
                    'type' => $type->value,
                    'label' => $type->label(),
                    'version' => $consent->version,
                    'status' => $consent->status,
                    'status_label' => $consent->status()->label(),
                    'source' => $consent->source,
                    'at' => ($consent->granted_at ?? $consent->withdrawn_at)
                        ?->toIso8601String(),
                ];
            })
            ->values()
            ->all();

        return response()->json(['data' => $history]);
    }

    private function failure(RuntimeException $e): JsonResponse
    {
        return match ($e->getMessage()) {
            'consent_unavailable' => response()->json([
                'message' => 'That permission is not available yet. FynnEdge '
                    .'will ask you when the feature behind it exists.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'consent_required' => response()->json([
                'message' => 'This permission is part of using FynnEdge and '
                    .'cannot be switched off here. To stop entirely, delete '
                    .'your account.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            'consent_not_granted' => response()->json([
                'message' => 'There is nothing to withdraw for that permission.',
            ], Response::HTTP_UNPROCESSABLE_ENTITY),

            default => throw $e,
        };
    }
}
