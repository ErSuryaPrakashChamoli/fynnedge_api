<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\User;

/**
 * Who may look at a customer.
 *
 * Every staff role may, because a panel where support cannot find the
 * customer they are helping is not a panel. What differs is how much of
 * that customer they see, which the resource decides field by field rather
 * than here — this answers "may they open the record at all".
 *
 * Nothing may be deleted. A customer's records are the evidence behind
 * their applications and consents, and a delete button in an admin panel is
 * how that evidence disappears.
 */
class UserPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return true;
    }

    public function view(AdminUser $admin, User $customer): bool
    {
        return true;
    }

    public function create(AdminUser $admin): bool
    {
        // Customers create themselves, by proving a mobile number. An
        // administrator inventing one would produce an account nobody can
        // sign in to and a person who never agreed to anything.
        return false;
    }

    public function update(AdminUser $admin, User $customer): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, User $customer): bool
    {
        return false;
    }
}
