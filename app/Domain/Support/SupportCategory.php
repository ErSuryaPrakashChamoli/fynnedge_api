<?php

namespace App\Domain\Support;

/**
 * What a customer is asking about.
 *
 * Deliberately small, and deliberately shaped like the product rather than
 * like a support department. A customer picks the thing they were doing —
 * an application, a document, their score — not a queue name.
 *
 * Every case here corresponds to something FynnEdge actually has. There is
 * no "billing" because FynnEdge does not bill anybody, and no "complaint"
 * because there is no complaints process to route one into.
 */
enum SupportCategory: string
{
    case Application = 'application';
    case Documents = 'documents';
    case SubmissionStatus = 'submission_status';
    case FinancialGuidance = 'financial_guidance';
    case FynnAi = 'fynnai';
    case FynnScore = 'fynnscore';
    case FynnMatch = 'fynnmatch';
    case FynnTrust = 'fynntrust';
    case FynnTwin = 'fynntwin';
    case Account = 'account';
    case Privacy = 'privacy';
    case Technical = 'technical';
    case General = 'general';

    /** What the customer reads. Never the enum name. */
    public function label(): string
    {
        return match ($this) {
            self::Application => 'Application help',
            self::Documents => 'Document help',
            self::SubmissionStatus => 'Application status',
            self::FinancialGuidance => 'Financial guidance',
            self::FynnAi => 'Help with FynnAI',
            self::FynnScore => 'Understanding my FynnScore',
            self::FynnMatch => 'Finding the right option',
            self::FynnTrust => 'Understanding FynnTrust',
            self::FynnTwin => 'Understanding a simulation',
            self::Account => 'Account help',
            self::Privacy => 'Privacy and consent',
            self::Technical => 'Technical issue',
            self::General => 'Something else',
        };
    }

    /**
     * One line under the label, so a customer picks the right one rather
     * than the first one.
     */
    public function description(): string
    {
        return match ($this) {
            self::Application => 'Something about an application you started.',
            self::Documents => 'A document that will not upload, or was not accepted.',
            self::SubmissionStatus => 'Where an application has got to, and what happens next.',
            self::FinancialGuidance => 'Whether borrowing makes sense for you right now.',
            self::FynnAi => 'An answer FynnAI gave, or one it could not.',
            self::FynnScore => 'What your FynnScore means and what moves it.',
            self::FynnMatch => 'Which of the options suits you.',
            self::FynnTrust => 'How FynnEdge ranks the options it shows you.',
            self::FynnTwin => 'A before-and-after simulation you ran.',
            self::Account => 'Signing in, your details, or closing your account.',
            self::Privacy => 'Your data, your permissions, or withdrawing one.',
            self::Technical => 'Something in the app is not working.',
            self::General => 'Anything that does not fit the rest.',
        };
    }

    /**
     * The order they are offered in.
     *
     * Most-asked first, and the two that are about a customer being stuck —
     * an application and its status — ahead of the ones that are about
     * understanding something.
     */
    public static function inOfferedOrder(): array
    {
        return [
            self::SubmissionStatus,
            self::Application,
            self::Documents,
            self::FinancialGuidance,
            self::FynnScore,
            self::FynnMatch,
            self::FynnTrust,
            self::FynnTwin,
            self::FynnAi,
            self::Account,
            self::Privacy,
            self::Technical,
            self::General,
        ];
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
