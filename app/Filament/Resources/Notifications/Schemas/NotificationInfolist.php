<?php

namespace App\Filament\Resources\Notifications\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class NotificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('title'),
                TextEntry::make('body')->columnSpanFull(),
                TextEntry::make('category')->badge(),
                TextEntry::make('type'),
                TextEntry::make('read_at')->dateTime()->placeholder('Unread'),
                TextEntry::make('created_at')->label('Sent')->dateTime(),
            ])->columns(2),
        ]);
    }
}
