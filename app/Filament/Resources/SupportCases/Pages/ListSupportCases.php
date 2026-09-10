<?php

namespace App\Filament\Resources\SupportCases\Pages;

use App\Filament\Resources\SupportCases\SupportCaseResource;
use Filament\Resources\Pages\ListRecords;

class ListSupportCases extends ListRecords
{
    protected static string $resource = SupportCaseResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
