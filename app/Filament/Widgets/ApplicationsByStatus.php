<?php

namespace App\Filament\Widgets;

use App\Domain\Application\ApplicationStatus;
use App\Models\AdminUser;
use App\Models\LoanApplication;
use Filament\Widgets\ChartWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Where applications actually are, counted from the table.
 *
 * Every status appears even at zero, so the shape of the pipeline is
 * visible rather than only its busy parts — an empty "declined" is
 * information, and a chart that hides it reads as though nothing is ever
 * declined.
 */
class ApplicationsByStatus extends ChartWidget
{
    protected ?string $heading = 'Applications by status';

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    public static function canView(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser && $admin->role()->seesFinancialDetail();
    }

    protected function getType(): string
    {
        return 'bar';
    }

    protected function getData(): array
    {
        $counts = LoanApplication::query()
            ->selectRaw('status, COUNT(*) as total')
            ->groupBy('status')
            ->pluck('total', 'status');

        $labels = [];
        $values = [];

        foreach (ApplicationStatus::cases() as $status) {
            $labels[] = $status->label();
            $values[] = (int) ($counts[$status->value] ?? 0);
        }

        return [
            'datasets' => [[
                'label' => 'Applications',
                'data' => $values,
            ]],
            'labels' => $labels,
        ];
    }
}
