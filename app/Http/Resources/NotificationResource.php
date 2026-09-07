<?php

namespace App\Http\Resources;

use App\Models\Notification;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property-read Notification $resource
 */
class NotificationResource extends JsonResource
{
    /**
     * Everything the notification centre needs, and nothing else.
     *
     * Not the idempotency key, which describes which internal event
     * produced this and is FynnEdge's bookkeeping. Not delivery state: a
     * customer reading a message in the app has no use for whether a push
     * was also attempted, and it would be the only place a fixture's
     * "simulated" could be mistaken for something about them.
     */
    public function toArray(Request $request): array
    {
        $n = $this->resource;

        return [
            'id' => (string) $n->id,
            'type' => $n->type,
            'category' => $n->category,
            'title' => $n->title,
            'body' => $n->body,

            // Always a fynnedge:// link the router recognises, or null.
            'deep_link' => $n->deep_link,

            'read' => $n->isRead(),
            'read_at' => $n->read_at?->toIso8601String(),
            'created_at' => $n->created_at?->toIso8601String(),
        ];
    }
}
