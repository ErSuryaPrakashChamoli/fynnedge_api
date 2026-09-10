<?php

namespace App\Filament\Resources\LoanApplications;

use App\Filament\Resources\LoanApplications\Pages\ListLoanApplications;
use App\Filament\Resources\LoanApplications\Pages\ViewLoanApplication;
use App\Filament\Resources\LoanApplications\Schemas\LoanApplicationInfolist;
use App\Filament\Resources\LoanApplications\Tables\LoanApplicationsTable;
use App\Models\LoanApplication;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Loan applications, read-only.
 *
 * An application changes state when the customer acts on it or a provider
 * answers, and every one of those transitions is written by the domain
 * services with an application event recorded beside it. Editing `status`
 * from a panel would be a second way to move the same field that leaves no
 * such trace — the record would say a thing happened without saying who
 * decided it or why.
 */
class LoanApplicationResource extends Resource
{
    protected static ?string $model = LoanApplication::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static string|UnitEnum|null $navigationGroup = 'Loans';

    protected static ?string $navigationLabel = 'Loan Applications';

    protected static ?string $recordTitleAttribute = 'reference';

    public static function infolist(Schema $schema): Schema
    {
        return LoanApplicationInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return LoanApplicationsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLoanApplications::route('/'),
            'view' => ViewLoanApplication::route('/{record}'),
        ];
    }
}
