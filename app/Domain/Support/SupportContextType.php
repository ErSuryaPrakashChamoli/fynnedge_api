<?php

namespace App\Domain\Support;

/**
 * What a support request can be about.
 *
 * An allowlist, and the reason it is an allowlist rather than a model class
 * name is the obvious one: `context_type` arrives in a request body, and a
 * field that names a class is a field that names any class. Nothing here
 * reaches a model except through ContextResolver, which looks each of these
 * up through the customer's own relation.
 *
 * Three of these carry an identifier and three do not. A request about a
 * FynnScore is about the customer's own score and there is nothing to name;
 * a request about an application is about one particular application, and
 * which one has to be checked.
 */
enum SupportContextType: string
{
    // --- Carry an identifier, and ownership is checked ---------------------
    case Application = 'application';
    case Document = 'document';
    case Goal = 'goal';

    // --- Carry nothing but where the customer was ------------------------
    case FynnAi = 'fynnai';
    case FynnScore = 'fynnscore';
    case FinancialProfile = 'financial_profile';
    case Vault = 'vault';
    case Privacy = 'privacy';
    case General = 'general';

    /** Whether this context names a particular record. */
    public function needsId(): bool
    {
        return in_array($this, [self::Application, self::Document, self::Goal], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Application => 'Application',
            self::Document => 'Document',
            self::Goal => 'Goal',
            self::FynnAi => 'FynnAI',
            self::FynnScore => 'FynnScore',
            self::FinancialProfile => 'Your financial details',
            self::Vault => 'FynnVault',
            self::Privacy => 'Privacy and consent',
            self::General => 'General',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $t) => $t->value, self::cases());
    }
}
