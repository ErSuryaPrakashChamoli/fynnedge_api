<?php

namespace App\Filament\Widgets;

use App\Domain\Application\ApplicationStatus;
use App\Models\AdminUser;
use App\Models\Document;
use App\Models\LoanApplication;
use App\Models\SupportCase;
use App\Models\User;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Auth;

/**
 * What FynnEdge actually holds, counted from the tables.
 *
 * Every figure here is a query. Nothing is estimated, projected or carried
 * over from a previous period, and there are no placeholder numbers waiting
 * to be made real — a dashboard that shows a plausible figure nobody
 * computed is worse than one that shows nothing, because somebody will
 * repeat it in a meeting.
 *
 * The money and lending figures are withheld from roles that may not read a
 * customer's finances, which is the same rule the customer resource follows.
 */
class FynnEdgeOverview extends StatsOverviewWidget
{
    protected ?string $pollingInterval = null;

    private function admin(): ?AdminUser
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser ? $admin : null;
    }

    protected function getStats(): array
    {
        $stats = [
            Stat::make('Customers', (string) User::query()->count())
                ->description('Registered mobile numbers')
                ->color('primary'),
        ];

        if ($this->admin()?->role()->seesFinancialDetail()) {
            $stats = array_merge($stats, $this->lendingStats());
        }

        $stats[] = Stat::make('Documents in vault', (string) Document::query()->count())
            ->description('Uploaded by customers');

        $stats[] = Stat::make(
            'Open support cases',
            (string) SupportCase::query()
                ->whereNotIn('status', ['resolved', 'closed'])
                ->count(),
        )->description('Not resolved or closed');

        return $stats;
    }

    /** @return array<int, Stat> */
    private function lendingStats(): array
    {
        $total = LoanApplication::query()->count();

        $byStatus = fn (ApplicationStatus ...$statuses): int => LoanApplication::query()
            ->whereIn('status', array_map(fn (ApplicationStatus $s): string => $s->value, $statuses))
            ->count();

        $pending = $byStatus(
            ApplicationStatus::Submitted,
            ApplicationStatus::UnderReview,
            ApplicationStatus::ActionNeeded,
        );

        $approved = $byStatus(ApplicationStatus::Approved, ApplicationStatus::Disbursed);
        $declined = $byStatus(ApplicationStatus::Declined);

        // Summed over what customers asked for, not over anything a lender
        // agreed to — no live provider is connected, so an "approved amount"
        // would be a number FynnEdge invented.
        $requested = (string) LoanApplication::query()->sum('amount');

        return [
            Stat::make('Loan applications', (string) $total)
                ->description('All time'),

            Stat::make('Awaiting an outcome', (string) $pending)
                ->description('Submitted, under review or action needed')
                ->color($pending > 0 ? 'warning' : 'gray'),

            Stat::make('Approved', (string) $approved)
                ->description('Includes disbursed')
                ->color('success'),

            Stat::make('Declined', (string) $declined)
                ->color($declined > 0 ? 'danger' : 'gray'),

            Stat::make('Total requested', '₹'.number_format((float) $requested, 2))
                ->description('Sum of application amounts'),
        ];
    }
}
