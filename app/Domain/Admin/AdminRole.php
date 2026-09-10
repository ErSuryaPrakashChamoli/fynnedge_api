<?php

namespace App\Domain\Admin;

/**
 * What a member of FynnEdge staff is allowed to do.
 *
 * Six roles rather than a permission matrix, because the panel is small and
 * the questions it has to answer are coarse: can this person see customer
 * money, can they change what the catalogue offers, can they create another
 * administrator. A per-resource permission table would let those be tuned
 * individually and would also let somebody assemble a combination nobody
 * ever reviewed.
 *
 * `Advisor` is the application's own word, not a new one — support messages
 * have carried `author_type = advisor` since Module 30, and staff answering
 * a customer's case are that same role.
 *
 * A role is deliberately not a hierarchy of numbers. Seniority is expressed
 * by what each one is granted below, so a reader can see that a Team Leader
 * cannot reach the catalogue without working out whether 3 outranks 4.
 */
enum AdminRole: string
{
    /** Everything, including creating and disabling other staff. */
    case SuperAdmin = 'super_admin';

    /** The business view: lending, catalogue, customers, no staff admin. */
    case BusinessHead = 'business_head';

    /** Runs lending operations day to day. */
    case Manager = 'manager';

    /** Leads a support/operations team. */
    case TeamLeader = 'team_leader';

    /** Answers customers. The role support messages already name. */
    case Advisor = 'advisor';

    /** Reads. Changes nothing, anywhere. */
    case Viewer = 'viewer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::BusinessHead => 'Business Head',
            self::Manager => 'Manager',
            self::TeamLeader => 'Team Leader',
            self::Advisor => 'Advisor',
            self::Viewer => 'Viewer',
        };
    }

    /**
     * May administer other staff accounts.
     *
     * One role, on purpose. Anybody who can create an account can create one
     * with any role, so this is the same permission as "may grant any
     * permission" and there is no safe way to hand it out more widely.
     */
    public function managesStaff(): bool
    {
        return $this === self::SuperAdmin;
    }

    /**
     * May see a customer's money: income, obligations, credit, applications.
     *
     * Withheld from Advisor deliberately. Answering "where is my document"
     * does not require knowing what somebody earns, and the smallest set of
     * people who can read a customer's finances is the right one.
     */
    public function seesFinancialDetail(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::BusinessHead, self::Manager => true,
            self::TeamLeader, self::Advisor, self::Viewer => false,
        };
    }

    /**
     * May see identity and credit-bureau records.
     *
     * The most restricted category in the panel: KYC payloads and credit
     * reports are the records a customer would least expect to be browsed.
     */
    public function seesIdentityAndCredit(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::BusinessHead, self::Manager => true,
            default => false,
        };
    }

    /** May change anything at all, as opposed to reading it. */
    public function canWrite(): bool
    {
        return $this !== self::Viewer;
    }

    /** May change what FynnEdge offers customers. */
    public function managesCatalogue(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::BusinessHead => true,
            default => false,
        };
    }

    /** May work a support case. */
    public function handlesSupport(): bool
    {
        return match ($this) {
            self::SuperAdmin, self::Manager, self::TeamLeader, self::Advisor => true,
            default => false,
        };
    }

    /** @return array<string, string> value => label, for a Filament select. */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $role) {
            $options[$role->value] = $role->label();
        }

        return $options;
    }
}
