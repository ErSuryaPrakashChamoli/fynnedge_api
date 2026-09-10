<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\SupportCase;

/**
 * Customer support requests.
 *
 * The one place staff genuinely act rather than observe: an advisor answers
 * a case, and the answer is a message attached to it. Even here nothing is
 * edited — a reply is appended, and the case's status moves through the
 * SupportCaseStatus machine rather than being typed in.
 */
class SupportCasePolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->handlesSupport() || $admin->role()->seesFinancialDetail();
    }

    public function view(AdminUser $admin, SupportCase $case): bool
    {
        return $this->viewAny($admin);
    }

    /** Cases are opened by customers, never by staff on their behalf. */
    public function create(AdminUser $admin): bool
    {
        return false;
    }

    /** Working a case — replying, acknowledging, resolving. */
    public function update(AdminUser $admin, SupportCase $case): bool
    {
        return $admin->role()->handlesSupport();
    }

    public function delete(AdminUser $admin, SupportCase $case): bool
    {
        return false;
    }

    public function deleteAny(AdminUser $admin): bool
    {
        return false;
    }
}
