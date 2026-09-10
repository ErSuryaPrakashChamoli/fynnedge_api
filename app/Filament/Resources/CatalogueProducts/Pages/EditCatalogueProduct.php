<?php

namespace App\Filament\Resources\CatalogueProducts\Pages;

use App\Filament\Resources\CatalogueProducts\CatalogueProductResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditCatalogueProduct extends EditRecord
{
    protected static string $resource = CatalogueProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
