<?php

namespace App\Filament\Resources\ProviderEvents\Pages;

use App\Filament\Resources\ProviderEvents\ProviderEventResource;
use Filament\Resources\Pages\ListRecords;

class ListProviderEvents extends ListRecords
{
    protected static string $resource = ProviderEventResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
