<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NotificationDevice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where a customer's app can be reached, if anything could reach it.
 *
 * NO PUSH PROVIDER IS CONNECTED, so a registered token is currently a
 * record and nothing more. The endpoints exist because the alternative is
 * building them later against a provider's deadline, and because the
 * ownership rules below are easier to get right now than under one.
 *
 * A token is never returned. Not in the registration response, not in a
 * list, not in an error — the response says which registration exists, and
 * the address of somebody's handset stays on the server.
 */
class NotificationDeviceController extends Controller
{
    /**
     * POST /notifications/devices
     *
     * Registering a token that is already registered to somebody else
     * moves it. That is correct rather than permissive: a handset belongs
     * to whoever is signed in on it, and leaving the previous registration
     * active would send one customer's notifications to a phone another
     * customer is holding.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string', 'min:8', 'max:255'],
            'platform' => ['required', 'string', Rule::in(['ios', 'android'])],
        ]);

        $device = DB::transaction(function () use ($request, $validated) {
            /*
             * Whoever held this token before stops holding it.
             *
             * Deleted rather than deactivated. A deactivated row would keep
             * the address of a handset tied to a customer who no longer has
             * it, which is a record with no purpose and a token FynnEdge
             * has no reason to hold — and the unique index on the token is
             * a hard guarantee that this is the only registration for this
             * device, which is what stops one customer's notifications
             * reaching a phone another is holding.
             */
            NotificationDevice::query()
                ->where('token', $validated['token'])
                ->where('user_id', '!=', $request->user()->getKey())
                ->delete();

            /** @var NotificationDevice $device */
            $device = $request->user()->notificationDevices()->updateOrCreate(
                ['token' => $validated['token']],
                [
                    'platform' => $validated['platform'],
                    'active' => true,
                    'last_seen_at' => now(),
                ],
            );

            return $device;
        });

        return response()->json([
            'data' => $this->describe($device),
        ], $device->wasRecentlyCreated ? Response::HTTP_CREATED : Response::HTTP_OK);
    }

    /**
     * DELETE /notifications/devices/{device}
     *
     * Deactivated through the customer's own relation, so another
     * customer's device id is not found rather than refused, and cannot be
     * switched off by someone who guessed it.
     */
    public function destroy(Request $request, string $device): JsonResponse
    {
        $model = $request->user()->notificationDevices()->find($device);

        abort_if($model === null, Response::HTTP_NOT_FOUND, 'Device not found.');

        $model->update(['active' => false]);

        return response()->json(null, Response::HTTP_NO_CONTENT);
    }

    /** @return array<string, mixed> */
    private function describe(NotificationDevice $device): array
    {
        return [
            'id' => (string) $device->id,
            'platform' => $device->platform,
            'active' => (bool) $device->active,
            // Deliberately no token, and no fragment of one.
        ];
    }
}
