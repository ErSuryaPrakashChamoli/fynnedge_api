<?php

namespace App\Policies\Concerns;

use App\Models\AdminUser;

/**
 * The default posture for a customer's records in the panel: readable by
 * the roles allowed to see them, and changed by nobody.
 *
 * Every one of these tables is written by a domain service that also
 * records why — an application event, a consent ledger entry, a
 * notification the customer was actually sent. A panel that could edit the
 * row directly would be a second writer with no such account of itself,
 * and the record would stop being evidence of what happened.
 */
trait ReadOnlyForStaff
{
    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, mixed $record): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, mixed $record): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }

    public function restore(AdminUser $admin, mixed $record): bool
    {
        return false;
    }

    public function forceDelete(AdminUser $admin, mixed $record): bool
    {
        return false;
    }
}
