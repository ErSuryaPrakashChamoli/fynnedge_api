<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * What a customer is saving towards, and how much they have. Financial detail, so the roles that may not read their money may not read this either.
 */
class FinancialGoalPolicy
{
    use ReadOnlyForStaff;

    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->seesFinancialDetail();
    }

    public function view(AdminUser $admin, mixed $record): bool
    {
        return $admin->role()->seesFinancialDetail();
    }
}
