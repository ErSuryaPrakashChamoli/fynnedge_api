<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Policies\Concerns\ReadOnlyForStaff;

/**
 * What the customer agreed to, and when. Readable by every role: consent is the record that explains why FynnEdge was allowed to do anything, and staff answering for the product need to see it.
 */
class ConsentPolicy
{
    use ReadOnlyForStaff;

    public function viewAny(AdminUser $admin): bool
    {
        return true;
    }

    public function view(AdminUser $admin, mixed $record): bool
    {
        return true;
    }
}
