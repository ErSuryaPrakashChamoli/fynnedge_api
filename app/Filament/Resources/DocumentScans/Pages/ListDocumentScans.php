<?php

namespace App\Filament\Resources\DocumentScans\Pages;

use App\Filament\Resources\DocumentScans\DocumentScanResource;
use Filament\Resources\Pages\ListRecords;

class ListDocumentScans extends ListRecords
{
    protected static string $resource = DocumentScanResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
