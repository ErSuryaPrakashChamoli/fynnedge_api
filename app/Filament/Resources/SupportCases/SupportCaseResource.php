<?php

namespace App\Filament\Resources\SupportCases;

use App\Filament\Resources\SupportCases\Pages\ListSupportCases;
use App\Filament\Resources\SupportCases\Pages\ViewSupportCase;
use App\Filament\Resources\SupportCases\RelationManagers\MessagesRelationManager;
use App\Filament\Resources\SupportCases\Schemas\SupportCaseInfolist;
use App\Filament\Resources\SupportCases\Tables\SupportCasesTable;
use App\Models\SupportCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Customer support requests — the one place staff act rather than observe.
 *
 * An advisor answers by appending a message; nothing already said is
 * edited, by either party. The case's status moves through
 * SupportCaseStatus, which refuses to go backwards, so a resolved case
 * cannot quietly become open again.
 */
class SupportCaseResource extends Resource
{
    protected static ?string $model = SupportCase::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLifebuoy;

    protected static string|UnitEnum|null $navigationGroup = 'Support';

    protected static ?string $navigationLabel = 'Support Cases';

    protected static ?string $recordTitleAttribute = 'reference';

    /** Open cases, so the count in the sidebar is the queue. */
    public static function getNavigationBadge(): ?string
    {
        $open = static::getModel()::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->count();

        return $open > 0 ? (string) $open : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return 'warning';
    }

    public static function infolist(Schema $schema): Schema
    {
        return SupportCaseInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SupportCasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [
            MessagesRelationManager::class,
        ];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSupportCases::route('/'),
            'view' => ViewSupportCase::route('/{record}'),
        ];
    }
}
