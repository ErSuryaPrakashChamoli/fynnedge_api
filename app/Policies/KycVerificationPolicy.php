<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * Identity verification attempts. The most restricted category in the panel alongside credit: these records concern a customer's identity documents, and the smallest set of people who can read them is the right one.
 */
class KycVerificationPolicy
{
    use ReadOnlyForStaff;

    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->seesIdentityAndCredit();
    }

    public function view(AdminUser $admin, mixed $record): bool
    {
        return $admin->role()->seesIdentityAndCredit();
    }
}
