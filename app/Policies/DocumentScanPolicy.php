<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * What a document reader extracted from a customer's file. Extracted fields can contain identity and income detail, so this follows the identity rule rather than the financial one.
 */
class DocumentScanPolicy
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
