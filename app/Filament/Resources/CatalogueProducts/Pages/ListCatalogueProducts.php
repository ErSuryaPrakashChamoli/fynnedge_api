<?php

namespace App\Filament\Resources\CatalogueProducts\Pages;

use App\Filament\Resources\CatalogueProducts\CatalogueProductResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCatalogueProducts extends ListRecords
{
    protected static string $resource = CatalogueProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
