<?php

namespace App\Policies;

use App\Models\AdminUser;
use App\Models\LoanApplication;

/**
 * Who may look at a loan application.
 *
 * An application states what somebody earns, what they already owe and what
 * they are asking for, so it is financial detail and the roles that may not
 * read a customer's money may not read one.
 *
 * Read-only throughout. An application moves through its states by the
 * customer acting on it or a provider answering, and every one of those
 * transitions is written by the domain services with an event recorded
 * beside it. A panel that could set `status` directly would be a second,
 * unaudited way to change the same field.
 */
class LoanApplicationPolicy
{
    public function viewAny(AdminUser $admin): bool
    {
        return $admin->role()->seesFinancialDetail();
    }

    public function view(AdminUser $admin, LoanApplication $application): bool
    {
        return $admin->role()->seesFinancialDetail();
    }

    public function create(AdminUser $admin): bool
    {
        return false;
    }

    public function update(AdminUser $admin, LoanApplication $application): bool
    {
        return false;
    }

    public function delete(AdminUser $admin, LoanApplication $application): bool
    {
        return false;
    }
}
