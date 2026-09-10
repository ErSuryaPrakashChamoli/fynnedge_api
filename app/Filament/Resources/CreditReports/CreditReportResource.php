<?php

namespace App\Filament\Resources\CreditReports;

use App\Filament\Resources\CreditReports\Pages\ListCreditReports;
use App\Filament\Resources\CreditReports\Pages\ViewCreditReport;
use App\Filament\Resources\CreditReports\Schemas\CreditReportInfolist;
use App\Filament\Resources\CreditReports\Tables\CreditReportsTable;
use App\Models\CreditReport;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Credit bureau results.
 *
 * The score and the scale it sits on, and nothing else. `bureau_reference`
 * is the bureau's own handle for the enquiry and is not shown: it is the
 * key somebody would need to pull the full report elsewhere.
 */
class CreditReportResource extends Resource
{
    protected static ?string $model = CreditReport::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = 'KYC & Documents';

    protected static ?string $navigationLabel = 'Credit Reports';

    protected static ?string $recordTitleAttribute = 'id';

    public static function infolist(Schema $schema): Schema
    {
        return CreditReportInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CreditReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCreditReports::route('/'),
            'view' => ViewCreditReport::route('/{record}'),
        ];
    }
}
