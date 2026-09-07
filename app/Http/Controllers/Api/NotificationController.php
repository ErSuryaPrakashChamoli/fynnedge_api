<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\NotificationResource;
use App\Models\Notification;
use App\Services\Notifications\NotificationCentre;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The notification centre.
 *
 * Everything here is scoped through `$request->user()->notifications()`, so
 * another customer's id is not found rather than refused — the same 404,
 * with the same body, as an id that never existed. Nothing in a request
 * decides whose notifications are involved.
 *
 * Notifications are never created here. They come from things that happened
 * to the customer's applications, documents and goals, decided by
 * NotificationPolicy; an endpoint that could write one would be an endpoint
 * a client could use to put words in FynnEdge's mouth.
 */
class NotificationController extends Controller
{
    public function __construct(private readonly NotificationCentre $centre) {}

    /**
     * GET /notifications
     *
     * Newest first, a page at a time. The unread count travels in the meta
     * so the badge does not cost a second request, and it counts rather
     * than loads: it is never derived from the page.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.NotificationCentre::MAX_PAGE],

            // The id of the oldest notification already held. Ids descend,
            // so "older than this" is a single indexed comparison and does
            // not drift when something new arrives mid-scroll the way an
            // offset would.
            'before' => ['sometimes', 'integer', 'min:1'],
        ]);

        $page = $this->centre->page(
            $request->user(),
            limit: (int) ($validated['limit'] ?? NotificationCentre::DEFAULT_PAGE),
            before: isset($validated['before']) ? (int) $validated['before'] : null,
        );

        return response()->json([
            'data' => NotificationResource::collection($page->items)->resolve(),
            'meta' => [
                'unread_count' => $this->centre->unreadCount($request->user()),
                'next_cursor' => $page->nextCursor,
                'has_more' => $page->nextCursor !== null,
            ],
        ]);
    }

    public function show(Request $request, string $notification): NotificationResource
    {
        return new NotificationResource($this->owned($request, $notification));
    }

    /**
     * GET /notifications/unread-count
     *
     * One number, one indexed count. Home asks for this and nothing else,
     * so a badge never costs a page of notifications.
     */
    public function unreadCount(Request $request): JsonResponse
    {
        return response()->json([
            'data' => ['unread_count' => $this->centre->unreadCount($request->user())],
        ]);
    }

    /**
     * POST /notifications/{notification}/read
     *
     * Idempotent: reading something twice leaves the first read_at alone,
     * so the time shown is when they actually first saw it.
     */
    public function markRead(Request $request, string $notification): NotificationResource
    {
        return new NotificationResource(
            $this->centre->markRead($this->owned($request, $notification))
        );
    }

    /**
     * POST /notifications/read-all
     *
     * Safe to repeat, and scoped by the relation — one customer's read-all
     * cannot reach another's rows, because the update is issued through
     * their own relation rather than against the table.
     */
    public function markAllRead(Request $request): JsonResponse
    {
        return response()->json([
            'data' => [
                'marked' => $this->centre->markAllRead($request->user()),
                'unread_count' => $this->centre->unreadCount($request->user()),
            ],
        ]);
    }

    /**
     * Resolved through the customer's own relation.
     *
     * Not route-model binding: that finds the row first and checks
     * ownership afterwards, which makes the difference between "not yours"
     * and "no such thing" observable.
     */
    private function owned(Request $request, string $id): Notification
    {
        $notification = $request->user()->notifications()->find($id);

        abort_if($notification === null, Response::HTTP_NOT_FOUND, 'Notification not found.');

        return $notification;
    }
}
