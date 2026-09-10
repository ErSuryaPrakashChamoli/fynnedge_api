<?php

namespace App\Filament\Resources\DocumentScans;

use App\Filament\Resources\DocumentScans\Pages\ListDocumentScans;
use App\Filament\Resources\DocumentScans\Pages\ViewDocumentScan;
use App\Filament\Resources\DocumentScans\Schemas\DocumentScanInfolist;
use App\Filament\Resources\DocumentScans\Tables\DocumentScansTable;
use App\Models\DocumentScan;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Attempts to read a customer's document.
 *
 * Status and provenance only. The fields a scan extracted live in
 * document_scan_fields and can contain income and identity detail, so they
 * are not surfaced here — this answers "was it read, and did it work",
 * which is the operational question.
 */
class DocumentScanResource extends Resource
{
    protected static ?string $model = DocumentScan::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMagnifyingGlass;

    protected static string|UnitEnum|null $navigationGroup = 'KYC & Documents';

    protected static ?string $navigationLabel = 'Document Scans';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return DocumentScanInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentScansTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocumentScans::route('/'),
            'view' => ViewDocumentScan::route('/{record}'),
        ];
    }
}
