<?php

namespace App\Filament\Resources\Consents;

use App\Filament\Resources\Consents\Pages\ListConsents;
use App\Filament\Resources\Consents\Pages\ViewConsent;
use App\Filament\Resources\Consents\Schemas\ConsentInfolist;
use App\Filament\Resources\Consents\Tables\ConsentsTable;
use App\Models\Consent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What each customer agreed to, and when.
 *
 * An append-only ledger: a withdrawal is a new row rather than an edit to
 * the old one, which is why nothing here is writable. It is the record
 * that explains why FynnEdge was permitted to do anything at all, so it
 * has to survive intact.
 */
class ConsentResource extends Resource
{
    protected static ?string $model = Consent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCheckBadge;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Consents';

    protected static ?string $recordTitleAttribute = 'type';

    public static function infolist(Schema $schema): Schema
    {
        return ConsentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ConsentsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListConsents::route('/'),
            'view' => ViewConsent::route('/{record}'),
        ];
    }
}
