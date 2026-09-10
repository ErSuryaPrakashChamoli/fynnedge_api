<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\Provider;

/**
 * Readable by every role — staff answering a customer need to know what was
 * offered — and writable only by the roles that own the catalogue, because
 * changing one changes what customers are shown.
 *
 * Deleting is never allowed: applications record the product and version
 * they were quoted from, and removing one would orphan that history.
 * Retire it instead.
 */
class ProviderPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return true;
    }

    public function view(AdminUser $admin, Provider $record): bool
    {
        return true;
    }

    public function create(AdminUser $admin): bool
    {
        return $admin->role()->managesCatalogue();
    }

    public function update(AdminUser $admin, Provider $record): bool
    {
        return $admin->role()->managesCatalogue();
    }

    public function delete(AdminUser $admin, Provider $record): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
