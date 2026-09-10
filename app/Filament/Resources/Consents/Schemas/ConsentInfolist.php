<?php

namespace App\Filament\Resources\Consents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ConsentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('type')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('version'),
                TextEntry::make('source'),
                TextEntry::make('granted_at')->dateTime()->placeholder('—'),
                TextEntry::make('withdrawn_at')->dateTime()->placeholder('—'),
            ])->columns(2),
        ]);
    }
}
