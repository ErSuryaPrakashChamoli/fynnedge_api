<?php

namespace App\Filament\Resources\FinancialGoals;

use App\Filament\Resources\FinancialGoals\Pages\ListFinancialGoals;
use App\Filament\Resources\FinancialGoals\Pages\ViewFinancialGoal;
use App\Filament\Resources\FinancialGoals\Schemas\FinancialGoalInfolist;
use App\Filament\Resources\FinancialGoals\Tables\FinancialGoalsTable;
use App\Models\FinancialGoal;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * What customers are saving towards.
 *
 * Financial detail, so the roles that may not read a customer's money may
 * not read these either. Read-only: a goal is the customer's own statement
 * of intent, and staff editing one would be putting words in their mouth.
 */
class FinancialGoalResource extends Resource
{
    protected static ?string $model = FinancialGoal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static string|UnitEnum|null $navigationGroup = 'Customers';

    protected static ?string $navigationLabel = 'Financial Goals';

    protected static ?string $recordTitleAttribute = 'title';

    public static function infolist(Schema $schema): Schema
    {
        return FinancialGoalInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return FinancialGoalsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListFinancialGoals::route('/'),
            'view' => ViewFinancialGoal::route('/{record}'),
        ];
    }
}
