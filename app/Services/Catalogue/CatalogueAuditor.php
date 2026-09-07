<?php

namespace App\Services\Catalogue;

use App\Models\CatalogueAuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/**
 * What changed in the catalogue, who changed it, and what it was before.
 *
 * Deliberately small: catalogue operations record themselves, and nothing
 * else uses this. A generic audit framework would collect far more and be
 * trusted far less.
 *
 * Two rules the recording obeys. The actor is taken from the server, never
 * from anything a caller passed — an audit trail whose author can be
 * asserted by the author is worthless. And before/after hold only the fields
 * an operation meant to change; dumping a whole request is how credentials
 * end up in an audit log.
 */
class CatalogueAuditor
{
    public const PROVIDER_CREATED = 'provider_created';

    public const PROVIDER_UPDATED = 'provider_updated';

    public const PRODUCT_CREATED = 'product_created';

    public const PRODUCT_ACTIVATED = 'product_activated';

    public const PRODUCT_DEACTIVATED = 'product_deactivated';

    public const VERSION_CREATED = 'version_created';

    public const VERSION_UPDATED = 'version_updated';

    public const VERSION_PUBLISHED = 'version_published';

    /**
     * How this change arrived, when no authenticated user made it.
     *
     * FynnEdge has no administrator accounts: the only way to hold a token
     * is to verify an OTP, and everyone who does is a customer. Catalogue
     * changes therefore come from an operator with server access, and the
     * honest record of that is the console, not a fabricated user id.
     */
    private ?string $consoleActor = null;

    public function actingFromConsole(string $label): void
    {
        $this->consoleActor = $label;
    }

    /**
     * @param  array<string, mixed>|null  $before
     * @param  array<string, mixed>|null  $after
     */
    public function record(
        string $action,
        string $entityType,
        int $entityId,
        ?array $before = null,
        ?array $after = null,
    ): CatalogueAuditEvent {
        /** @var User|null $user */
        $user = Auth::user();

        return CatalogueAuditEvent::create([
            'actor_user_id' => $user?->id,
            'actor_label' => $user !== null
                ? 'user:'.$user->id
                : ($this->consoleActor ?? 'console'),
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'before' => $before,
            'after' => $after,
        ]);
    }
}
