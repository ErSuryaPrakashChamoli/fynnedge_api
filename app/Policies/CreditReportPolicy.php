<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * Credit bureau records. Restricted to the roles that may read identity and credit, and the score is the only figure the panel shows.
 */
class CreditReportPolicy
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
