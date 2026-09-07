<?php

namespace App\Domain\Ai;

use App\Domain\Financial\FinancialEngine;
use App\Domain\Money;
use App\Models\Conversation;
use App\Services\CustomerService;
use App\Services\FinanceService;

/**
 * The assistant FynnEdge actually has.
 *
 * Not a language model, and it does not claim to be one — the API says so in
 * every response. It reads the customer's real context and explains real
 * figures, and every number in every answer comes from a FynnEdge engine
 * rather than from arithmetic written here.
 *
 * The rule it exists to enforce: where a figure is missing, it says so. It
 * never assumes an income, invents a goal, reports a lender decision, or
 * describes what a document contains.
 */
class DeterministicFynnAiProvider implements FynnAiProvider
{
    public function __construct(
        private readonly FinancialEngine $financial,
        private readonly FinanceService $finance,
        private readonly CustomerService $customers,
    ) {}

    public function isAvailable(): bool
    {
        return true;
    }

    public function name(): string
    {
        return 'fynnedge-rules-v1';
    }

    /**
     * False, and said out loud. An assistant built from fixed rules is
     * useful; calling it a language model would be a claim about how the
     * answer was produced that happens not to be true.
     */
    public function isLanguageModel(): bool
    {
        return false;
    }

    public function generate(
        FynnAiContext $context,
        Conversation $conversation,
        string $message,
    ): AiReply {
        $question = mb_strtolower(trim($message));

        return match (true) {
            $this->asksAbout($question, ['document', 'statement', 'salary slip', 'payslip', 'upload', 'vault', 'pan card', 'aadhaar']) => $this->documents($context, $question),

            $this->asksAbout($question, ['score', 'fynnscore']) => $this->score($context),

            $this->asksAbout($question, ['application', 'applied', 'my loan status']) => $this->applications($context),

            $this->asksAbout($question, ['goal', 'saving for', 'target']) => $this->goals($context),

            $this->asksAbout($question, ['afford', 'emi', 'borrow', 'lakh', 'loan']) => $this->affordability($context, $message),

            $this->asksAbout($question, ['how am i doing', 'summary', 'position', 'overview']) => $this->summary($context),

            default => $this->fallback($context),
        };
    }

    // --- Answers ------------------------------------------------------------

    /**
     * What FynnEdge holds about the customer's money, in its own figures.
     */
    private function summary(FynnAiContext $context): AiReply
    {
        if (! $context->hasFinancialProfile()) {
            return $this->needsProfile(
                'I do not have your income or expenses yet, so there is '
                .'nothing for me to work from.'
            );
        }

        $f = $context->financialProfile;

        $text = 'Here is where you stand on the figures you have given me.'
            ."\n\n"
            .'Income '.$this->money($f['monthly_income']).' a month, with '
            .$this->money($f['monthly_outgo']).' going out — '
            .$this->money($f['monthly_expenses']).' of expenses and '
            .$this->money($f['existing_emi']).' of EMIs. That leaves '
            .$this->money($f['surplus']).".\n\n"
            .'Your EMIs use '.$this->percent($f['emi_ratio']).'% of your '
            .'income, and your savings cover about '
            .$this->number($f['emergency_months']).' months of outgoings.';

        if ($context->fynnScore !== null) {
            $text .= "\n\nYour FynnScore is ".$context->fynnScore['score']
                .' out of 100 — '
                .mb_strtolower($context->fynnScore['band_label']).'.';
        }

        return new AiReply(
            text: $text,
            suggestions: ['Explain my FynnScore', 'Can I afford another EMI?'],
            actions: [
                ['label' => 'Financial profile', 'route' => '/financial-profile'],
            ],
            groundedIn: $f,
        );
    }

    /**
     * The score, explained with the engine's own dimensions and words.
     *
     * No weights are quoted and no formula is described: the FynnScore engine
     * owns both, and an assistant paraphrasing them would be a second
     * definition waiting to drift.
     */
    private function score(FynnAiContext $context): AiReply
    {
        if ($context->fynnScore === null) {
            return $this->needsProfile(
                'I cannot work out your FynnScore yet. It needs your monthly '
                .'income to measure everything else against.'
            );
        }

        $score = $context->fynnScore;

        $text = 'Your FynnScore is '.$score['score'].' out of 100 — '
            .mb_strtolower($score['band_label']).".\n\n"
            .'It is built from three things:';

        foreach ($score['dimensions'] as $dimension) {
            $text .= "\n\n• ".$dimension['name'].' — '.$dimension['score']
                .'/100. '.$dimension['explanation'];
        }

        $helping = array_values(array_filter(
            $score['dimensions'],
            fn (array $d) => $d['is_helping'],
        ));

        if ($helping !== []) {
            $text .= "\n\n".$helping[0]['name'].' is doing the most for you '
                .'right now.';
        }

        return new AiReply(
            text: $text,
            suggestions: ['How do I improve it?', 'How am I doing financially?'],
            actions: [['label' => 'Open FynnScore', 'route' => '/fynn-score']],
            groundedIn: $score,
        );
    }

    /**
     * What a new EMI would do, from the Financial Engine's projection.
     */
    private function affordability(FynnAiContext $context, string $message): AiReply
    {
        if (! $context->hasFinancialProfile()) {
            return $this->needsProfile(
                'To tell you what a loan would mean for your month I need '
                .'your income and existing EMIs.'
            );
        }

        $amount = $this->extractAmount($message);
        $f = $context->financialProfile;

        $snapshot = $this->financial->calculate(
            income: Money::of($f['monthly_income']),
            expenses: Money::of($f['monthly_expenses']),
            existingEmi: Money::of($f['existing_emi']),
            otherObligations: Money::of($f['other_obligations']),
            savings: Money::of($f['savings']),
        );

        if ($amount === null) {
            // No amount to price, so the honest answer is the headroom the
            // engine reports and an invitation to name a figure.
            $headroom = $this->financial->project($snapshot, Money::zero());

            return new AiReply(
                text: 'On the figures you have given me, EMIs up to '
                    .$this->money($headroom->emiCeiling->toJsonNumber())
                    .' a month sit inside the '
                    .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                    .'% of income FynnEdge uses as its affordability '
                    .'reference. You are using '
                    .$this->money($f['existing_emi']).' of that, leaving '
                    .$this->money($headroom->headroomBefore->toJsonNumber())
                    .".\n\nTell me an amount and I will work out the EMI and "
                    .'what it would leave you each month.',
                suggestions: ['What about ₹5 lakh?', 'Show me the actual offers'],
                actions: [['label' => 'See loan options', 'route' => '/loan-options']],
                groundedIn: $headroom->toArray(),
            );
        }

        // Priced by FinanceService, projected by FinancialEngine. Neither
        // calculation is repeated here.
        $pricing = $this->finance->summary((float) $amount, 13.25, 60);
        $projection = $this->financial->project(
            $snapshot,
            Money::of($pricing['emi']),
        );

        $text = 'A loan of '.$this->money($amount).' over 5 years at a '
            .'typical business rate of 13.25% works out to about '
            .$this->money($pricing['emi'])." a month.\n\n"
            .'With your existing EMIs that would take your repayments to '
            .$this->percent($projection->projectedEmiRatio->toJsonNumber())
            .'% of your income, and leave you '
            .$this->money($projection->projectedSurplus->toJsonNumber())
            .' a month.';

        $text .= $projection->withinCeiling
            ? "\n\nThat sits inside the "
                .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                .'% of income FynnEdge treats as its affordability reference.'
            : "\n\nThat is past the "
                .$this->percent(FinancialEngine::AFFORDABILITY_CEILING_PERCENT)
                .'% of income FynnEdge treats as its affordability reference.';

        // FynnEdge's reference is FynnEdge's. It says nothing about what a
        // provider would decide.
        $text .= ' That is FynnEdge\'s own reference, not a lender rule — '
            .'whether a provider offers you this, and on what terms, is '
            .'theirs to decide.';

        return new AiReply(
            text: $text,
            suggestions: ['What if I take it over 7 years?', 'Show me the actual offers'],
            actions: [
                ['label' => 'See loan options', 'route' => '/loan-options'],
                ['label' => 'Open EMI calculator', 'route' => '/fynnlab/emi'],
            ],
            groundedIn: $projection->toArray(),
        );
    }

    /** The customer's own goals, with the engine's figures. */
    private function goals(FynnAiContext $context): AiReply
    {
        if ($context->goals === []) {
            return new AiReply(
                text: 'You have not set a goal yet. If you add one — what you '
                    .'are saving for, how much, and by when — I can tell you '
                    .'what it needs each month.',
                suggestions: ['How am I doing financially?'],
                actions: [['label' => 'Add a goal', 'route' => '/goals']],
            );
        }

        $text = 'You have '.count($context->goals).' goal'
            .(count($context->goals) === 1 ? '' : 's').' on the go.';

        foreach ($context->goals as $goal) {
            $text .= "\n\n• ".$goal['title'].' — '
                .$this->money($goal['saved_amount']).' of '
                .$this->money($goal['target_amount']).' saved ('
                .round($goal['progress'] * 100).'%). ';

            $text .= $goal['months_remaining'] > 0
                ? 'That needs '.$this->money($goal['monthly_required'])
                    .' a month over '.$goal['months_remaining'].' months.'
                : 'The target date has passed.';
        }

        return new AiReply(
            text: $text,
            suggestions: ['How am I doing financially?'],
            actions: [['label' => 'Open goals', 'route' => '/goals']],
            groundedIn: ['goals' => $context->goals],
        );
    }

    /**
     * The customer's applications, as FynnEdge recorded them.
     *
     * Nothing here reports a provider action, because no provider has taken
     * one.
     */
    private function applications(FynnAiContext $context): AiReply
    {
        if ($context->applications === []) {
            return new AiReply(
                text: 'You have not started an application through FynnEdge '
                    .'yet. When you do, I can tell you exactly where it '
                    .'stands.',
                suggestions: ['Show me the actual offers'],
                actions: [['label' => 'See loan options', 'route' => '/loan-options']],
            );
        }

        $text = '';

        foreach ($context->applications as $application) {
            $text .= ($text === '' ? '' : "\n\n")
                .$application['reference'].' — '
                .$application['product_name'].' with '
                .$application['lender'].', '
                .$this->money($application['amount']).' over '
                .$application['tenure_months'].' months. Status: '
                .$application['status_label'].'.';

            if ($application['is_simulated']) {
                $text .= ' FynnEdge recorded this; no provider has received '
                    .'it, because lender submission is not connected yet.';
            }
        }

        return new AiReply(
            text: $text,
            suggestions: ['How am I doing financially?'],
            actions: [['label' => 'Open applications', 'route' => '/applications']],
            groundedIn: ['applications' => $context->applications],
        );
    }

    /**
     * What is in the vault, and the hard line at its contents.
     */
    private function documents(FynnAiContext $context, string $question): AiReply
    {
        $asksContents = $this->asksAbout($question, [
            'what does', 'say', 'show', 'read', 'according to', 'inside',
            'amount on', 'balance',
        ]);

        if ($context->documents === []) {
            return new AiReply(
                text: 'You have nothing in FynnVault yet. Anything you add is '
                    .'stored privately in your FynnEdge account.',
                suggestions: ['How am I doing financially?'],
                actions: [['label' => 'Open FynnVault', 'route' => '/vault']],
            );
        }

        $text = 'You have '.count($context->documents).' document'
            .(count($context->documents) === 1 ? '' : 's').' in FynnVault:';

        foreach ($context->documents as $document) {
            $text .= "\n\n• ".$document['name'].' — '.$document['type']
                .', stored '.$document['uploaded_at'].'.';
        }

        // The boundary, stated whenever a document is mentioned and always
        // when the customer asks what one says.
        $text .= "\n\n"
            .'I can see what is stored and what it is filed under, but not '
            .'what is inside it. FynnEdge is not connected to a '
            .'document-reading service, so nothing has been read or '
            .'interpreted.';

        return new AiReply(
            text: $text,
            suggestions: $asksContents
                ? ['How am I doing financially?']
                : ['Explain my FynnScore'],
            actions: [['label' => 'Open FynnVault', 'route' => '/vault']],
            groundedIn: ['documents' => $context->documents],
        );
    }

    private function fallback(FynnAiContext $context): AiReply
    {
        $text = 'I can work with your own figures — what you earn, what goes '
            .'out, your goals, your FynnScore, and anything you have applied '
            ."for.\n\nTell me what you are trying to work out.";

        if ($context->missing !== []) {
            $text .= "\n\nOne thing to know: I do not have "
                .$this->listMissing($context->missing)
                .' yet, so I will say so rather than guess if it comes up.';
        }

        return new AiReply(
            text: $text,
            suggestions: [
                'How am I doing financially?',
                'Explain my FynnScore',
                'Can I afford another EMI?',
            ],
            actions: [['label' => 'Financial tools', 'route' => '/fynnlab']],
        );
    }

    /** Missing information, named rather than filled in. */
    private function needsProfile(string $why): AiReply
    {
        return new AiReply(
            text: $why.' Add it to your Financial Profile and I can use it.',
            suggestions: ['What counts as income?'],
            actions: [
                ['label' => 'Financial profile', 'route' => '/financial-profile'],
            ],
        );
    }

    // --- Helpers ------------------------------------------------------------

    /** @param list<string> $terms */
    private function asksAbout(string $question, array $terms): bool
    {
        foreach ($terms as $term) {
            if (str_contains($question, $term)) {
                return true;
            }
        }

        return false;
    }

    private function extractAmount(string $question): ?float
    {
        $q = mb_strtolower($question);

        if (preg_match('/(\d+(?:\.\d+)?)\s*(lakh|lac)/', $q, $m)) {
            return (float) $m[1] * 100000;
        }
        if (preg_match('/(\d+(?:\.\d+)?)\s*(crore|cr)\b/', $q, $m)) {
            return (float) $m[1] * 10000000;
        }
        if (preg_match('/₹?\s*([\d,]{5,})/', $q, $m)) {
            $value = (float) str_replace(',', '', $m[1]);

            return $value >= 10000 ? $value : null;
        }

        return null;
    }

    /** @param list<string> $missing */
    private function listMissing(array $missing): string
    {
        $words = array_map(fn (string $key) => match ($key) {
            'monthly_income' => 'your monthly income',
            'monthly_expenses' => 'your monthly expenses',
            'goals' => 'any goals',
            'fynn_score' => 'a FynnScore',
            'applications' => 'any applications',
            'documents' => 'any documents',
            default => $key,
        }, $missing);

        if (count($words) === 1) {
            return $words[0];
        }

        $last = array_pop($words);

        return implode(', ', $words).' or '.$last;
    }

    /** The product's own grouping, from Money. */
    private function money(int|float|string $value): string
    {
        return Money::of($value)->toIndianString();
    }

    private function percent(int|float $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ''), '0'), '.');
    }

    private function number(int|float $value): string
    {
        return $this->percent($value);
    }
}
