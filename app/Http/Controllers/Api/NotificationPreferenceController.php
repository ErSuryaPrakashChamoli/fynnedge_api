<?php

namespace App\Http\Controllers\Api;

use App\Domain\Notifications\MarketingCommunication;
use App\Domain\Notifications\NotificationCategory;
use App\Domain\Notifications\NotificationDeliveryProvider;
use App\Domain\Notifications\NotificationPreferences;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * What a customer has chosen about being notified.
 *
 * The rule this endpoint exists to enforce: a preference changes how
 * FynnEdge reaches out and never what it records. Turning push off does not
 * empty the notification centre, and no category a customer needs in order
 * to act on their own application can be muted at all.
 *
 * Marketing is not here and cannot be. FynnEdge sends none, and the consent
 * that would govern it is a separate thing in a separate place — mixing the
 * two is how a customer who declined promotions stops being told their
 * document was rejected.
 */
class NotificationPreferenceController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->describe($request)]);
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'push_enabled' => ['sometimes', 'boolean'],
            'muted_categories' => ['sometimes', 'array'],
            'muted_categories.*' => [
                'string',
                // Only the categories a customer can do without. Asking to
                // mute applications or documents is refused here rather
                // than accepted and quietly ignored, so a client is told
                // the truth about what it asked for.
                Rule::in(NotificationPreferences::mutableCategoryIds()),
            ],
        ], [
            'muted_categories.*.in' => 'Application and document notifications '
                .'cannot be turned off — they are how you find out that '
                .'something you started needs you.',
        ]);

        $preference = $request->user()->notificationPreference()->firstOrCreate([]);

        // Only what was actually sent. An absent key leaves that choice
        // alone rather than resetting it to a default the client did not
        // ask for.
        $changes = [];

        if (array_key_exists('push_enabled', $validated)) {
            $changes['push_enabled'] = (bool) $validated['push_enabled'];
        }

        if (array_key_exists('muted_categories', $validated)) {
            $changes['muted_categories'] = array_values(array_unique(
                $validated['muted_categories']
            ));
        }

        $preference->update($changes);

        return response()->json(['data' => $this->describe($request)]);
    }

    /** @return array<string, mixed> */
    private function describe(Request $request): array
    {
        $preference = $request->user()->notificationPreference;

        return [
            'push_enabled' => $preference?->push_enabled ?? true,
            'muted_categories' => array_map(
                fn (NotificationCategory $c) => $c->value,
                $preference?->mutedCategories() ?? [],
            ),

            'mutable_categories' => NotificationPreferences::mutableCategoryIds(),

            /*
             * Stated rather than implied. A customer looking at notification
             * settings is entitled to know that push does not currently go
             * anywhere, and that FynnEdge does not send marketing at all.
             */
            'push_available' => app(NotificationDeliveryProvider::class)->isAvailable(),
            'marketing_available' => MarketingCommunication::isAvailable(),
        ];
    }
}
