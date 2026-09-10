<?php

namespace App\Filament\Resources\FinancialGoals\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class FinancialGoalInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('title'),
                TextEntry::make('category')->badge(),
                TextEntry::make('target_amount')->money('INR'),
                TextEntry::make('saved_amount')->money('INR'),
                TextEntry::make('target_date')->date(),
                TextEntry::make('status')->badge(),
                TextEntry::make('note')->placeholder('—')->columnSpanFull(),
            ])->columns(2),
        ]);
    }
}
