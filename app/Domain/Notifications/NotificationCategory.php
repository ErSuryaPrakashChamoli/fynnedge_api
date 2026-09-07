<?php

namespace App\Domain\Notifications;

/**
 * What part of FynnEdge a notification is about.
 *
 * The category is what a customer uses to make sense of a list, and what a
 * preference can switch off. It is deliberately coarse: five things a
 * customer already recognises from the app's own navigation, not a taxonomy
 * of FynnEdge's internals.
 *
 * Marketing is not here. Promotional communication is a different kind of
 * message with a different lawful basis, and mixing it into this enum is
 * how a service notification ends up suppressed by a marketing preference.
 * See MarketingCommunication for where that boundary is written down.
 */
enum NotificationCategory: string
{
    case Application = 'application';
    case Document = 'document';
    case FinancialHealth = 'financial_health';
    case Goal = 'goal';
    case System = 'system';

    public function label(): string
    {
        return match ($this) {
            self::Application => 'Applications',
            self::Document => 'Documents',
            self::FinancialHealth => 'Financial health',
            self::Goal => 'Goals',
            self::System => 'Service',
        };
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
