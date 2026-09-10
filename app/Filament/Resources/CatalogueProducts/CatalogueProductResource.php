<?php

namespace App\Filament\Resources\CatalogueProducts;

use App\Filament\Resources\CatalogueProducts\Schemas\CatalogueProductForm;
use App\Filament\Resources\CatalogueProducts\Tables\CatalogueProductsTable;
use App\Models\CatalogueProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What FynnEdge offers customers.
 *
 * The product is the stable thing; its terms live in versions, which are
 * published and then immutable. This resource edits the product's identity
 * and status only — pricing is changed by drafting a new version through
 * the catalogue console commands, so that every quote a customer was given
 * still resolves to the exact terms they were shown.
 */
class CatalogueProductResource extends Resource
{
    protected static ?string $model = CatalogueProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSquares2x2;

    protected static string|UnitEnum|null $navigationGroup = 'Products';

    protected static ?string $navigationLabel = 'Catalogue Products';

    protected static ?string $recordTitleAttribute = 'display_name';

    public static function form(Schema $schema): Schema
    {
        return CatalogueProductForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CatalogueProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => Pages\ListCatalogueProducts::route('/'),
            'edit' => Pages\EditCatalogueProduct::route('/{record}/edit'),
        ];
    }
}
