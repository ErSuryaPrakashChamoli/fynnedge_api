<?php

namespace App\Services\Catalogue;

use App\Models\AdminUser;
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
     * How this change arrived, when nobody is signed in to have made it.
     *
     * Catalogue changes come from three places now: a console command run
     * by an operator with server access, a member of staff in the admin
     * panel, or nobody — and the label has to say which. A fabricated user
     * id would be worse than an honest "console".
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

        /*
         * Staff are checked for first, and recorded by label alone.
         *
         * `actor_user_id` is a foreign key into `users`, which holds
         * customers; an administrator's id there would resolve to whichever
         * customer happens to share the number, and an audit trail that
         * names the wrong person is worse than one that names nobody.
         * Their identity goes in the label, which is free text, until
         * somebody decides a queryable staff column is worth a migration.
         */
        $admin = Auth::guard('admin')->user();

        if ($admin instanceof AdminUser) {
            return CatalogueAuditEvent::create([
                'actor_user_id' => null,
                'actor_label' => 'admin:'.$admin->getKey().' '.$admin->email,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'action' => $action,
                'before' => $before,
                'after' => $after,
            ]);
        }

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
