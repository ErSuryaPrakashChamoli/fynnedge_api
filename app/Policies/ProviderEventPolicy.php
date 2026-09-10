<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * What a provider told FynnEdge. An operations log rather than a customer record, and read-only by nature: the events are history.
 */
class ProviderEventPolicy
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
