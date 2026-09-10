<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * A customer's uploaded files. Support needs to see that a document exists to answer "did it arrive"; nobody here can open one, because the panel lists metadata and never streams bytes.
 */
class DocumentPolicy
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
