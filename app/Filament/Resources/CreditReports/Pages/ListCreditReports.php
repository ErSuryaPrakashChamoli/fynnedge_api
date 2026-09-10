<?php

namespace App\Filament\Resources\CreditReports\Pages;

use App\Filament\Resources\CreditReports\CreditReportResource;
use Filament\Resources\Pages\ListRecords;

class ListCreditReports extends ListRecords
{
    protected static string $resource = CreditReportResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
