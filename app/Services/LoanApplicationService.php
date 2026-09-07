<?php

namespace App\Services;

use App\Domain\Application\ApplicationStatus;
use App\Domain\Application\ApplicationSubmissionGateway;
use App\Domain\Catalog\LoanProduct;
use App\Domain\Catalog\ProductRepository;
use App\Domain\Financial\FinancialEngine;
use App\Domain\Match\FynnMatchEngine;
use App\Domain\Match\MatchRequest;
use App\Domain\Match\ProductMatch;
use App\Domain\Money;
use App\Models\ApplicationEvent;
use App\Models\LoanApplication;
use App\Models\User;
use App\Services\Notifications\CustomerNotifications;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The application lifecycle, in one place.
 *
 * Two rules run through all of it:
 *
 *  1. The customer's figures are recorded exactly as they chose them. The
 *     amount, tenure and product are never defaulted or substituted, and the
 *     pricing stored is the pricing they were shown.
 *  2. FynnEdge only ever reports what FynnEdge did. Anything a provider would
 *     decide comes from a gateway response or does not appear at all.
 */
class LoanApplicationService
{
    public function __construct(
        private readonly ProductRepository $products,
        private readonly FynnMatchEngine $matching,
        private readonly FinancialEngine $financial,
        private readonly ApplicationSubmissionGateway $gateway,
        private readonly CustomerNotifications $notifications,
    ) {}

    /** The wording a customer acknowledges at submission. */
    public const ACKNOWLEDGEMENT_VERSION = 'v1';

    /** @return list<string> */
    public static function acknowledgements(): array
    {
        return [
            'The information I have given FynnEdge is accurate to the best of '
                .'my knowledge.',
            'I understand the provider decides whether to offer this loan, on '
                .'their own terms, and that FynnEdge does not control that '
                .'decision.',
        ];
    }

    /**
     * Starts an application from a product the customer picked.
     *
     * Repeating the call with the same idempotency key returns the same
     * application rather than starting a second one — a double tap is one
     * intention, not two.
     *
     * @param  array{product_id: string, amount: mixed, tenure_months: int}  $input
     */
    public function create(
        User $user,
        array $input,
        ?string $idempotencyKey = null,
    ): LoanApplication {
        if ($idempotencyKey !== null) {
            $existing = $user->applications()
                ->where('idempotency_key', $idempotencyKey)
                ->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        $product = $this->products->find($input['product_id']);

        if ($product === null) {
            throw new RuntimeException('product_unavailable');
        }

        // Price it through the same engine the customer saw, at exactly the
        // amount and tenure they chose.
        $snapshot = $this->financial->snapshot(
            app(CustomerService::class)->financialProfileFor($user)
        );

        $match = $this->matching->evaluate(
            $product,
            new MatchRequest(
                amount: Money::of($input['amount']),
                tenureMonths: (int) $input['tenure_months'],
                purpose: FynnMatchEngine::purposeForCategory($product->category),
            ),
            $snapshot,
            $user->customerProfile?->age,
        );

        try {
            return $this->insert($user, $input, $product, $match, $idempotencyKey);
        } catch (UniqueConstraintViolationException $e) {
            // Two requests carrying the same key both passed the check above
            // before either had written. The database is what actually
            // settles it, and it has: one row exists, and it is the answer to
            // both requests. A duplicate tap is one intention, and the second
            // caller gets their application rather than a server error.
            $existing = $idempotencyKey === null
                ? null
                : $user->applications()
                    ->where('idempotency_key', $idempotencyKey)
                    ->first();

            if ($existing === null) {
                throw $e;
            }

            return $existing;
        }
    }

    /**
     * @param  array{product_id: string, amount: mixed, tenure_months: int}  $input
     */
    private function insert(
        User $user,
        array $input,
        LoanProduct $product,
        ProductMatch $match,
        ?string $idempotencyKey,
    ): LoanApplication {
        return DB::transaction(function () use ($user, $input, $product, $match, $idempotencyKey) {
            $application = $user->applications()->create([
                'reference' => $this->nextReference(),
                'product_id' => $product->id,
                'product_name' => $product->name,
                'lender' => $product->lender,
                'product_category' => $product->category,

                // Verbatim. The tenure is the customer's, not the priced one:
                // if a product cannot serve it, that is a fact about the
                // application, not something to quietly correct.
                'amount' => Money::of($input['amount'])->toDecimalString(),
                'tenure_months' => (int) $input['tenure_months'],

                'interest_rate' => $product->interestRate,
                'emi' => $match->emi->toDecimalString(),
                'total_interest' => $match->totalInterest->toDecimalString(),
                'total_payable' => $match->totalPayable->toDecimalString(),
                'processing_fee' => $match->processingFee->toDecimalString(),
                'projected_emi_ratio' => $match->projectedEmiRatio(),
                'match_category' => $match->category->value,

                'status' => ApplicationStatus::Started->value,
                'idempotency_key' => $idempotencyKey,

                /*
                 * Which catalogue version produced the terms above.
                 *
                 * Recorded alongside the snapshot, never instead of it.
                 * Nothing resolves these back to a live price — they exist
                 * so an application at 11.5% can be traced to the version
                 * that offered 11.5%, after that version has been closed
                 * and replaced.
                 */
                'catalogue_product_code' => $product->provider === null
                    ? null
                    : $product->id,
                'product_version_id' => $product->versionId,
                'product_version_effective_from' => $product->versionEffectiveFrom,
            ]);

            $application->recordEvent(
                'Started',
                'Application created from a matched product.',
                ApplicationEvent::SOURCE_FYNNEDGE,
            );

            // Inside the transaction, so an application that does not
            // survive creation is not one the customer was told about.
            // A repeat of the same idempotent request returns above and
            // never reaches here, so nobody is told twice.
            $this->notifications->applicationStarted($application);

            return $application;
        });
    }

    /**
     * Changes the figures on a draft. Only a draft: once something has been
     * sent, what was sent is what was sent.
     *
     * @param  array{amount?: mixed, tenure_months?: int}  $input
     */
    public function update(LoanApplication $application, array $input): LoanApplication
    {
        if (! $application->status()->isEditable()) {
            throw new RuntimeException('not_editable');
        }

        $amount = array_key_exists('amount', $input)
            ? Money::of($input['amount'])
            : Money::of($application->amount);

        $tenure = (int) ($input['tenure_months'] ?? $application->tenure_months);

        $product = $this->products->find($application->product_id);

        if ($product === null) {
            throw new RuntimeException('product_unavailable');
        }

        $snapshot = $this->financial->snapshot(
            app(CustomerService::class)->financialProfileFor($application->user)
        );

        // Re-priced through the Financial Engine, never adjusted by hand.
        $match = $this->matching->evaluate(
            $product,
            new MatchRequest(
                amount: $amount,
                tenureMonths: $tenure,
                purpose: FynnMatchEngine::purposeForCategory($product->category),
            ),
            $snapshot,
            $application->user->customerProfile?->age,
        );

        $application->update([
            'amount' => $amount->toDecimalString(),
            'tenure_months' => $tenure,
            'emi' => $match->emi->toDecimalString(),
            'total_interest' => $match->totalInterest->toDecimalString(),
            'total_payable' => $match->totalPayable->toDecimalString(),
            'processing_fee' => $match->processingFee->toDecimalString(),
            'projected_emi_ratio' => $match->projectedEmiRatio(),
            'match_category' => $match->category->value,
        ]);

        return $application->fresh();
    }

    /**
     * Hands the application to the gateway.
     *
     * Submitting one that is already submitted returns it untouched and calls
     * no gateway: the row is locked for the check, so two requests arriving
     * together still produce one submission.
     */
    public function submit(LoanApplication $application): LoanApplication
    {
        return DB::transaction(function () use ($application) {
            /** @var LoanApplication $fresh */
            $fresh = LoanApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if ($fresh->status() === ApplicationStatus::Submitted) {
                return $fresh->fresh();
            }

            if (! $fresh->status()->canSubmit()) {
                throw new RuntimeException('not_submittable');
            }

            $result = $this->gateway->submit($fresh);

            if (! $result->accepted) {
                $fresh->recordEvent(
                    'Submission failed',
                    $result->failureReason ?? 'The submission was not accepted.',
                    $result->isSimulated
                        ? ApplicationEvent::SOURCE_SIMULATED
                        : ApplicationEvent::SOURCE_PROVIDER,
                    $result->at,
                );

                throw new RuntimeException('submission_failed');
            }

            $fresh->forceFill([
                'status' => $result->status->value,
                'submitted_at' => $result->at,
                'acknowledged_at' => now(),
                'acknowledgement_version' => self::ACKNOWLEDGEMENT_VERSION,
                'gateway' => $result->gateway,
                'is_simulated' => $result->isSimulated,
                'provider_reference' => $result->providerReference,
            ])->save();

            $fresh->recordEvent(
                'Submitted',
                $result->detail,
                $result->isSimulated
                    ? ApplicationEvent::SOURCE_SIMULATED
                    : ApplicationEvent::SOURCE_PROVIDER,
                $result->at,
            );

            return $fresh->fresh();
        });
    }

    /**
     * Withdraws it.
     *
     * Locked for the check, like submission: two taps arriving together
     * must produce one cancellation and one entry in the history, not two
     * of each on the same application.
     */
    public function cancel(LoanApplication $application): LoanApplication
    {
        return DB::transaction(function () use ($application) {
            /** @var LoanApplication $fresh */
            $fresh = LoanApplication::query()
                ->whereKey($application->getKey())
                ->lockForUpdate()
                ->first();

            if (! $fresh->status()->canCancel()) {
                throw new RuntimeException('not_cancellable');
            }

            $fresh->forceFill([
                'status' => ApplicationStatus::Cancelled->value,
                'cancelled_at' => now(),
            ])->save();

            $fresh->recordEvent(
                'Cancelled',
                'You withdrew this application.',
                ApplicationEvent::SOURCE_FYNNEDGE,
            );

            $this->notifications->applicationCancelled($fresh);

            return $fresh->fresh();
        });
    }

    /**
     * FE-YYYY-NNNNNN, sequential within the year.
     *
     * Readable enough to quote over the phone, and it carries nothing about
     * the customer.
     */
    private function nextReference(): string
    {
        $year = now()->year;
        $prefix = 'FE-'.$year.'-';

        $last = LoanApplication::query()
            ->where('reference', 'like', $prefix.'%')
            ->orderByDesc('reference')
            ->value('reference');

        $next = $last === null
            ? 1
            : ((int) substr($last, strlen($prefix))) + 1;

        return $prefix.str_pad((string) $next, 6, '0', STR_PAD_LEFT);
    }
}
