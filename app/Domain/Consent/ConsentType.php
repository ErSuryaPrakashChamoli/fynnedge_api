<?php

namespace App\Domain\Consent;

/**
 * Every consent FynnEdge knows about, and whether it means anything yet.
 *
 * A consent is only offered when the thing it authorises actually exists.
 * Collecting permission for a capability FynnEdge does not have produces a
 * record that looks like informed consent and is not: the customer agreed to
 * something that never happened, and the record would later be cited as if
 * they had.
 *
 * So the unavailable ones are declared here, refused by the API, and shown to
 * the customer as not available yet. They become grantable when — and only
 * when — the integration behind them is built.
 */
enum ConsentType: string
{
    // ---------------------------------------------------------------- live

    /** Using the figures the customer gives us to run FynnEdge. */
    case ServiceTerms = 'service_terms';

    /** Acknowledgement of what FynnEdge holds and why. */
    case PrivacyNotice = 'privacy_notice';

    /** FynnAI grounding its answers in the customer's own figures. */
    case AiMemory = 'ai_memory';

    // --------------------------------------------------------- not yet real

    /**
     * Requesting the customer's credit report from a bureau.
     *
     * Grantable only while a bureau is actually configured — see
     * isAvailable(). Module 10 withheld this because there was no
     * integration behind it and collecting permission for a capability that
     * does not exist produces a record that looks like informed consent and
     * is not. Module 23 built the integration; the consent follows the
     * capability rather than being switched on independently of it, so a
     * deployment with no bureau still does not offer it.
     */
    case CreditBureauCheck = 'credit_bureau_check';

    /**
     * Sending a document to a document-processing provider to read it.
     *
     * Purpose-limited and deliberately narrow: it permits extraction from a
     * document the customer chooses, and nothing else. It is not permission
     * to train a model on their documents, not permission to send one to a
     * lender, and not permission to process everything already in the vault.
     * Those are different questions with different answers, and bundling
     * them would make this one unreadable.
     *
     * Grantable only while a provider is configured, for the same reason as
     * the bureau consent above.
     */
    case DocumentProcessing = 'document_processing';

    /**
     * Using a verification service to check an identity document.
     *
     * A separate question from DocumentProcessing above, and deliberately
     * not folded into it. That one asks whether FynnEdge may send a
     * document somewhere to *read* it; this one asks whether FynnEdge may
     * have an identity *checked*. A customer can reasonably want the first
     * — save me typing my payslip — and not the second, and treating
     * permission for one as permission for the other would be helping
     * itself to an answer nobody gave.
     *
     * Grantable only while a verification provider is configured, for the
     * same reason as the consents above.
     */
    case IdentityVerification = 'identity_verification';

    /**
     * Sending an application to an external submission provider.
     *
     * Module 26 kept this type rather than adding a second one beside it.
     * It was written for exactly this question and has been withheld since
     * Module 10 precisely because the capability did not exist; a new
     * `lender_application_submission` alongside it would be two consents for
     * one act, and the customer would have to work out which governs what.
     *
     * Grantable only while a submission provider is configured, like every
     * other capability-backed consent here.
     */
    case LenderDataSharing = 'lender_data_sharing';

    /** Needs a training pipeline. There is none. */
    case AiTraining = 'ai_training';

    /** Needs somewhere to send messages from. There is nothing. */
    case MarketingMessages = 'marketing_messages';

    /**
     * The version of the wording this consent is currently collected under.
     *
     * Bumping it here starts a new version: existing records keep the version
     * they were granted under, and the customer is asked again.
     *
     * NOTE FOR PRODUCT/LEGAL: these are v1 of FynnEdge's own plain-English
     * descriptions of what the software does. No privacy policy or terms
     * document exists in this project yet. When that copy is written, it
     * lands here as v2 and every customer is asked to acknowledge it afresh.
     */
    public function currentVersion(): ?string
    {
        return match (true) {
            $this === self::ServiceTerms => 'v1',
            $this === self::PrivacyNotice => 'v1',
            $this === self::AiMemory => 'v1',

            // Versioned only while the capability exists, for the same
            // reason it is only grantable then.
            $this === self::CreditBureauCheck && $this->isAvailable() => 'v1',
            $this === self::DocumentProcessing && $this->isAvailable() => 'v1',
            $this === self::IdentityVerification && $this->isAvailable() => 'v1',
            $this === self::LenderDataSharing && $this->isAvailable() => 'v1',

            // Unavailable types have no version because there is nothing to
            // version: no wording has been written for a capability that
            // does not exist.
            default => null,
        };
    }

    /**
     * False where the capability behind the consent does not exist yet.
     *
     * The credit-bureau consent is the one that moves: it is offered exactly
     * when a bureau is configured, and withheld when none is. That keeps the
     * catalogue honest in both directions — a deployment with no bureau
     * never asks for permission it cannot use, and one with a bureau does
     * not perform a check nobody agreed to.
     */
    public function isAvailable(): bool
    {
        if ($this === self::CreditBureauCheck) {
            return config('fynnedge.credit.driver', 'none') !== 'none';
        }

        if ($this === self::DocumentProcessing) {
            return config('fynnedge.scan.driver', 'none') !== 'none';
        }

        if ($this === self::IdentityVerification) {
            return config('fynnedge.kyc.driver', 'none') !== 'none';
        }

        if ($this === self::LenderDataSharing) {
            return config('fynnedge.lender.driver', 'none') !== 'none';
        }

        return in_array($this, [
            self::ServiceTerms,
            self::PrivacyNotice,
            self::AiMemory,
        ], true);
    }

    /**
     * Required consents are what using FynnEdge at all involves.
     *
     * Withdrawing one is not a settings change — it is leaving. The optional
     * withdrawal endpoint refuses them, so turning off marketing can never
     * switch the product off.
     */
    public function isRequired(): bool
    {
        return in_array($this, [self::ServiceTerms, self::PrivacyNotice], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::ServiceTerms => 'Using FynnEdge',
            self::PrivacyNotice => 'How we use your information',
            self::AiMemory => 'FynnAI remembers your figures',
            self::CreditBureauCheck => 'Credit information check',
            self::DocumentProcessing => 'Reading your documents',
            self::IdentityVerification => 'Checking your identity document',
            self::LenderDataSharing => 'Sharing with a provider',
            self::AiTraining => 'Improving FynnAI',
            self::MarketingMessages => 'Offers and updates',
        };
    }

    /** What the software actually does. Not policy wording. */
    public function description(): string
    {
        return match ($this) {
            self::ServiceTerms => 'FynnEdge uses the income, expenses, EMIs and '
                .'savings you enter to work out your FynnScore, which products '
                .'fit, and what a loan would cost you. Without this there is '
                .'nothing for FynnEdge to calculate.',

            self::PrivacyNotice => 'Your figures are stored against your account '
                .'and used to produce the numbers you see in the app. They are '
                .'not sold, and they are not shared with anyone outside '
                .'FynnEdge.',

            self::AiMemory => 'FynnAI reads your financial profile so its '
                .'answers use your actual numbers instead of generic advice. '
                .'Turn this off and it answers without them.',

            // The description follows the capability, like the consent
            // itself. It says what a check is for and what it is not: a
            // bureau reports what your credit history looks like, and no
            // part of that is a decision by anyone to lend you money.
            self::CreditBureauCheck => $this->isAvailable()
                ? 'Lets FynnEdge ask a credit bureau for your credit score, '
                    .'so it can tell you whether products that publish a '
                    .'minimum score meet it. FynnEdge keeps the score, the '
                    .'scale it is on and when it was obtained — nothing else '
                    .'from the report. It is not a lending decision, and no '
                    .'provider sees it.'
                : 'Would let FynnEdge ask a credit bureau for your credit '
                    .'score. FynnEdge has no bureau connection, so there is '
                    .'nothing to permit yet.',

            // Purpose-limited, and says so in the sentence a customer
            // actually reads. It names what leaves FynnEdge, what comes
            // back, what is kept, and — because FynnScan reads a document
            // rather than checking one — what it does not establish.
            self::DocumentProcessing => $this->isAvailable()
                ? 'Lets FynnEdge send a document you choose to a '
                    .'document-processing provider, so it can read the '
                    .'details off it and save you typing them. FynnEdge '
                    .'keeps only the details the feature needs, and asks you '
                    .'to check them before anything is saved. It does not '
                    .'verify your document, and no lender sees it.'
                : 'Would let FynnEdge send a document to a '
                    .'document-processing provider to read it. FynnEdge has '
                    .'no such connection, so there is nothing to permit yet.',

            // Names the purpose, what leaves, what comes back, what is
            // kept, and — because a check is not an approval — what it does
            // not establish.
            self::IdentityVerification => $this->isAvailable()
                ? 'Lets FynnEdge send an identity document you choose to a '
                    .'verification service, so it can run its checks on the '
                    .'document and tell FynnEdge the name on it. FynnEdge '
                    .'compares that name with your profile and keeps only '
                    .'the result and the last few characters of the '
                    .'document number. It does not decide whether you get a '
                    .'loan, and no lender sees it.'
                : 'Would let FynnEdge have an identity document checked by a '
                    .'verification service. FynnEdge has no such connection, '
                    .'so there is nothing to permit yet.',

            // Names what leaves, who gets it, what comes back, and — because
            // sending an application is not being given a loan — what it
            // does not promise.
            self::LenderDataSharing => $this->isAvailable()
                ? 'Lets FynnEdge send this application, and the details you '
                    .'entered for it, to the application provider shown '
                    .'before you submit. FynnEdge sends your name, contact '
                    .'number, city, the figures you entered, and the '
                    .'product and terms you chose. It does not send your '
                    .'FynnScore, your credit information, your documents or '
                    .'anything FynnAI holds. Sending an application is not '
                    .'applying successfully: only the provider decides what '
                    .'happens next, and FynnEdge will tell you exactly what '
                    .'it hears back.'
                : 'Would let FynnEdge send an application to a provider. '
                    .'FynnEdge has no submission connection, so applications '
                    .'are recorded by FynnEdge and sent to nobody.',

            self::AiTraining => 'Would allow your conversations to be reviewed '
                .'to improve FynnAI. Nothing reviews them today.',

            self::MarketingMessages => 'Would allow FynnEdge to send you offers '
                .'and updates. FynnEdge sends none.',
        };
    }

    /** Why a type is not on offer, for the customer to read. */
    public function unavailableReason(): ?string
    {
        if ($this->isAvailable()) {
            return null;
        }

        return match ($this) {
            self::CreditBureauCheck => 'Available when a credit bureau '
                .'connection exists. FynnEdge will ask you then.',
            self::DocumentProcessing => 'Available when a document-reading '
                .'service exists. FynnEdge will ask you then.',
            self::IdentityVerification => 'Available when a verification '
                .'service exists. FynnEdge will ask you then.',
            self::LenderDataSharing => 'Available when a submission provider '
                .'exists. FynnEdge will ask you then.',
            self::AiTraining => 'Available if FynnEdge ever reviews '
                .'conversations. It does not today.',
            self::MarketingMessages => 'Available if FynnEdge starts sending '
                .'messages. It does not today.',
            default => null,
        };
    }

    /** @return list<self> */
    public static function available(): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $type) => $type->isAvailable(),
        ));
    }

    /** @return list<string> */
    public static function ids(): array
    {
        return array_map(fn (self $type) => $type->value, self::cases());
    }
}
