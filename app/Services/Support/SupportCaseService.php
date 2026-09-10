<?php

namespace App\Services\Support;

use App\Domain\References\SequentialReference;
use App\Domain\Support\CaseNotOpen;
use App\Domain\Support\InvalidCaseTransition;
use App\Domain\Support\ResolvedContext;
use App\Domain\Support\SupportCaseStatus;
use App\Domain\Support\SupportCategory;
use App\Domain\Support\SupportPriority;
use App\Models\AdminUser;
use App\Models\SupportCase;
use App\Models\SupportCaseEvent;
use App\Models\SupportCaseMessage;
use App\Models\User;
use App\Services\Notifications\CustomerNotifications;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Everything that happens to a support case.
 *
 * The three rules this class exists to hold.
 *
 * FynnEdge decides status and priority. Neither is fillable on the model
 * and neither is read from a request anywhere; a customer who could set
 * either could resolve their own request or make every request urgent.
 *
 * Transitions are checked. `SupportCaseStatus::canMoveTo()` is asked before
 * anything is written, so a case cannot go backwards from resolved because
 * a retry ran twice, and every move leaves an event saying who made it.
 *
 * Nothing advances on its own. There is no timer that acknowledges a case
 * and no rule that walks it to `in_progress`, because NO ADVISOR SYSTEM IS
 * CONNECTED and a status describing work nobody did is a lie a customer
 * would act on.
 */
class SupportCaseService
{
    public function __construct(
        private readonly CustomerNotifications $notifications,
    ) {}

    /** FE-SUP-YYYY-NNNNNN, allocated against the unique index. */
    private function references(): SequentialReference
    {
        return new SequentialReference('FE-SUP-', 'support_cases');
    }

    /**
     * Records a request for help.
     *
     * Idempotent on (user, key): a customer tapping "Get help" five times
     * has asked once, and the unique index decides that rather than a
     * read-then-write. The returned case is the same one every time.
     */
    public function open(
        User $user,
        SupportCategory $category,
        string $subject,
        string $description,
        ?ResolvedContext $context = null,
        ?string $idempotencyKey = null,
    ): SupportCase {
        if ($idempotencyKey !== null) {
            $existing = $user->supportCases()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $priority = SupportPriority::forRequest($category, $context);

        $case = $this->references()->allocate(
            fn (string $reference) => DB::transaction(function () use (
                $user, $reference, $category, $subject, $description,
                $context, $idempotencyKey, $priority
            ) {
                /** @var SupportCase $case */
                $case = $user->supportCases()->make([
                    'reference' => $reference,
                    'category' => $category->value,
                    'subject' => $subject,
                    'description' => $description,

                    // Safe fields only. Nothing from the record's contents.
                    'context_type' => $context?->type->value,
                    'context_id' => $context?->id,
                    'context_reference' => $context?->reference,
                    'context_status_label' => $context?->statusLabel,
                    'context_product_name' => $context?->productName,

                    'idempotency_key' => $idempotencyKey,
                ]);

                /*
                 * Status and priority are not fillable, deliberately — a
                 * customer able to mass-assign either could resolve their
                 * own request or make every request urgent. They are set
                 * here, before the insert rather than after it, so the row
                 * is never briefly written without them.
                 */
                $case->forceFill([
                    'status' => SupportCaseStatus::Open->value,
                    'priority' => $priority->value,
                    'last_customer_message_at' => now(),
                ])->save();

                $case->recordEvent(
                    'Request created',
                    SupportCaseEvent::SOURCE_CUSTOMER,
                    to: SupportCaseStatus::Open,
                );

                return $case;
            }),
        );

        $this->log('support_case_opened', $case);

        return $case;
    }

    /**
     * The customer adds something to a request that is still open.
     *
     * Their message is always authored as the customer. `author_type` is
     * not fillable and is never read from a request: a customer able to
     * author an advisor message on their own case could quote FynnEdge
     * saying whatever they liked.
     *
     * A case waiting for them moves back to in progress — the one automatic
     * transition in the module, and it is honest because the customer
     * genuinely did the thing that was being waited for.
     */
    public function addCustomerMessage(SupportCase $case, User $user, string $body): SupportCaseMessage
    {
        if (! $case->status()->acceptsCustomerMessages()) {
            throw new CaseNotOpen;
        }

        return DB::transaction(function () use ($case, $user, $body) {
            /** @var SupportCaseMessage $message */
            $message = $case->messages()->make(['body' => $body]);

            // Set before the insert, not after. `author_type` is not
            // fillable — it is the field a customer would set to `advisor`
            // if they could — so the server writes it here, every time.
            $message->forceFill([
                'author_type' => SupportCaseMessage::AUTHOR_CUSTOMER,
                'author_id' => $user->getKey(),
            ])->save();

            $case->forceFill(['last_customer_message_at' => now()])->save();

            $case->recordEvent(
                'You added information',
                SupportCaseEvent::SOURCE_CUSTOMER,
            );

            if ($case->status() === SupportCaseStatus::WaitingForCustomer) {
                $this->transition(
                    $case,
                    SupportCaseStatus::InProgress,
                    'Your information was received',
                    SupportCaseEvent::SOURCE_CUSTOMER,
                );
            }

            return $message;
        });
    }

    /**
     * FynnEdge answers.
     *
     * The mirror of addCustomerMessage, and it lives here for the same
     * reason that one does: `author_type` is written by the server, from
     * the side that actually acted, and never taken from a request. There
     * is deliberately no customer-facing route to this — an advisor message
     * reachable with a customer token would let somebody author FynnEdge's
     * half of their own case. Today the admin panel is the only caller; an
     * advisor API would call this same method rather than write its own.
     *
     * Answering a case that is merely open acknowledges it in passing,
     * because somebody has now looked at it and the customer should see
     * that reflected rather than being told nothing has happened.
     */
    public function addAdvisorMessage(
        SupportCase $case,
        AdminUser $advisor,
        string $body,
    ): SupportCaseMessage {
        if ($case->status()->isTerminal()) {
            throw new CaseNotOpen;
        }

        return DB::transaction(function () use ($case, $advisor, $body) {
            /** @var SupportCaseMessage $message */
            $message = $case->messages()->make(['body' => $body]);

            $message->forceFill([
                'author_type' => SupportCaseMessage::AUTHOR_ADVISOR,
                'author_id' => $advisor->getKey(),
            ])->save();

            $case->forceFill(['last_advisor_update_at' => now()])->save();

            $case->recordEvent(
                'FynnEdge replied',
                SupportCaseEvent::SOURCE_ADVISOR,
            );

            if ($case->status() === SupportCaseStatus::Open) {
                $this->transition(
                    $case,
                    SupportCaseStatus::Acknowledged,
                    'FynnEdge is looking into this',
                    SupportCaseEvent::SOURCE_ADVISOR,
                );
            }

            return $message;
        });
    }

    /**
     * The customer decides they no longer need help.
     *
     * Recorded with a reason, so `closed` does not quietly mean three
     * different things. A customer closing their own request and an advisor
     * resolving one are different acts and the timeline says which.
     */
    public function closeByCustomer(SupportCase $case): SupportCase
    {
        return $this->transition(
            $case,
            SupportCaseStatus::Closed,
            'You closed this request',
            SupportCaseEvent::SOURCE_CUSTOMER,
            closedReason: 'customer_closed',
        );
    }

    /**
     * Moves a case, or refuses to.
     *
     * The single write path for status. Every caller goes through here, so
     * there is one place that asks whether the move is possible and one
     * place that writes the event proving it happened.
     *
     * @throws InvalidCaseTransition
     */
    public function transition(
        SupportCase $case,
        SupportCaseStatus $to,
        string $label,
        string $source,
        ?string $closedReason = null,
    ): SupportCase {
        $from = $case->status();

        if (! $from->canMoveTo($to)) {
            throw new InvalidCaseTransition($from, $to);
        }

        return DB::transaction(function () use ($case, $from, $to, $label, $source, $closedReason) {
            $case->forceFill(array_filter([
                'status' => $to->value,
                'resolved_at' => $to === SupportCaseStatus::Resolved ? now() : null,
                'closed_at' => $to === SupportCaseStatus::Closed ? now() : null,
                'closed_reason' => $closedReason,
                'last_advisor_update_at' => $source === SupportCaseEvent::SOURCE_ADVISOR
                    ? now()
                    : null,
            ], fn ($v) => $v !== null))->save();

            $case->recordEvent($label, $source, from: $from, to: $to);

            $this->log('support_case_transitioned', $case, [
                'from' => $from->value,
                'to' => $to->value,
            ]);

            /*
             * And, where it is worth telling them, tell them.
             *
             * Through Module 29's policy, which decides. A customer closing
             * their own request does not need a notification about having
             * done it, and the policy is where that judgement lives.
             */
            $this->notifications->supportCaseMoved($case->fresh(), $from);

            return $case->fresh();
        });
    }

    /**
     * Operational facts only.
     *
     * Never the subject, never the description, never a message body. Those
     * are a customer's own words about their own money, and a log is the
     * easiest place in a system for them to end up somewhere nobody
     * intended. What is here is what an operator needs to find the case.
     *
     * @param  array<string, mixed>  $context
     */
    private function log(string $event, SupportCase $case, array $context = []): void
    {
        Log::info($event, [
            'support_case_reference' => $case->reference,
            'category' => $case->category,
            'status' => $case->status,
            'priority' => $case->priority,
            'user_id' => $case->user_id,
            ...$context,
        ]);
    }
}
