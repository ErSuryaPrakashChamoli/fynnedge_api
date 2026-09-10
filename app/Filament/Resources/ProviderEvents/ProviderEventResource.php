<?php

namespace App\Filament\Resources\ProviderEvents;

use App\Filament\Resources\ProviderEvents\Pages\ListProviderEvents;
use App\Filament\Resources\ProviderEvents\Pages\ViewProviderEvent;
use App\Filament\Resources\ProviderEvents\Schemas\ProviderEventInfolist;
use App\Filament\Resources\ProviderEvents\Tables\ProviderEventsTable;
use App\Models\ProviderEvent;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What providers have told FynnEdge.
 *
 * An ingress log. Read-only by nature — these are things that already
 * happened, and the reconciliation service is the only thing that writes
 * them. `payload_hash` and `idempotency_key` stay out of the table: they
 * are how a delivery is de-duplicated, not information about a customer.
 */
class ProviderEventResource extends Resource
{
    protected static ?string $model = ProviderEvent::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedInboxArrowDown;

    protected static string|UnitEnum|null $navigationGroup = 'System';

    protected static ?string $navigationLabel = 'Provider Events';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return ProviderEventInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ProviderEventsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListProviderEvents::route('/'),
            'view' => ViewProviderEvent::route('/{record}'),
        ];
    }
}
