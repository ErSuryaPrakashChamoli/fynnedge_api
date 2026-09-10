<?php

namespace App\Policies;

use App\Models\AdminUser;

/**
 * Who may administer staff accounts.
 *
 * Only a Super Admin, because creating an account includes choosing its
 * role, so anyone who can do this can grant themselves anything. Splitting
 * it finer would be an illusion of restraint.
 *
 * These policies answer for the `admin` guard. The customer API never calls
 * Gate at all — it scopes every query through the authenticated customer's
 * own relations — so nothing here can affect it.
 */
class AdminUserPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->managesStaff();
    }

    public function view(AdminUser $admin, AdminUser $target): bool
    {
        return $admin->role()->managesStaff();
    }

    public function create(AdminUser $admin): bool
    {
        return $admin->role()->managesStaff();
    }

    public function update(AdminUser $admin, AdminUser $target): bool
    {
        return $admin->role()->managesStaff();
    }

    /**
     * Nobody is deleted, including by a Super Admin.
     *
     * Staff names are attached to records that have to keep making sense
     * after the person leaves, and `is_active` already ends their access
     * without erasing who did what. A delete button here would be a way to
     * lose an audit trail by accident.
     */
    public function delete(AdminUser $admin, AdminUser $target): bool
    {
        return false;
    }

    public function restore(AdminUser $admin, AdminUser $target): bool
    {
        return false;
    }

    public function forceDelete(AdminUser $admin, AdminUser $target): bool
    {
        return false;
    }
}
