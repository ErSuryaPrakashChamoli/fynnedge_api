<?php

namespace App\Filament\Resources\CreditReports\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class CreditReportInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('score'),
                TextEntry::make('score_model')->label('Model'),
                TextEntry::make('score_min')->label('Scale minimum'),
                TextEntry::make('score_max')->label('Scale maximum'),
                TextEntry::make('bureau')->badge(),
                TextEntry::make('obtained_at')->dateTime(),
            ])->columns(2),
        ]);
    }
}
