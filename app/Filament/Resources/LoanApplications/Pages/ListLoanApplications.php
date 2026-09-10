<?php

namespace App\Filament\Resources\LoanApplications\Pages;

use App\Filament\Resources\LoanApplications\LoanApplicationResource;
use Filament\Resources\Pages\ListRecords;

class ListLoanApplications extends ListRecords
{
    protected static string $resource = LoanApplicationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
