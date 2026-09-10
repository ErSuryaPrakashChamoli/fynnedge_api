<?php

namespace App\Filament\Resources\Consents\Pages;

use App\Filament\Resources\Consents\ConsentResource;
use Filament\Resources\Pages\ListRecords;

class ListConsents extends ListRecords
{
    protected static string $resource = ConsentResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
