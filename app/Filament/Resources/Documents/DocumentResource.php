<?php

namespace App\Filament\Resources\Documents;

use App\Filament\Resources\Documents\Pages\ListDocuments;
use App\Filament\Resources\Documents\Pages\ViewDocument;
use App\Filament\Resources\Documents\Schemas\DocumentInfolist;
use App\Filament\Resources\Documents\Tables\DocumentsTable;
use App\Models\Document;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * A customer's vault documents, as metadata only.
 *
 * The panel lists that a file exists, what it claims to be and how big it
 * is. It does not stream one. FynnVault serves bytes through a single
 * authenticated, ownership-checked customer endpoint, and adding a second
 * door for staff would be the change that makes every document in the
 * vault reachable by whoever holds an admin session.
 *
 * `storage_key` and `storage_disk` are absent for the same reason: they
 * are where the file lives, and nothing in an admin table needs that.
 */
class DocumentResource extends Resource
{
    protected static ?string $model = Document::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedDocumentText;

    protected static string|UnitEnum|null $navigationGroup = 'KYC & Documents';

    protected static ?string $navigationLabel = 'Documents';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function infolist(Schema $schema): Schema
    {
        return DocumentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DocumentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDocuments::route('/'),
            'view' => ViewDocument::route('/{record}'),
        ];
    }
}
