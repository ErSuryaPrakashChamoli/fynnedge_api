<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * What FynnEdge told a customer. Support reads it to answer "I never got a message about that".
 */
class NotificationPolicy
{
    use ReadOnlyForStaff;

    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->handlesSupport() || $admin->role()->seesFinancialDetail();
    }

    public function view(AdminUser $admin, mixed $record): bool
    {
        return $admin->role()->handlesSupport() || $admin->role()->seesFinancialDetail();
    }
}
