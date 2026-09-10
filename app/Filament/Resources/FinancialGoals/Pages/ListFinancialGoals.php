<?php

namespace App\Filament\Resources\FinancialGoals\Pages;

use App\Filament\Resources\FinancialGoals\FinancialGoalResource;
use Filament\Resources\Pages\ListRecords;

class ListFinancialGoals extends ListRecords
{
    protected static string $resource = FinancialGoalResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
