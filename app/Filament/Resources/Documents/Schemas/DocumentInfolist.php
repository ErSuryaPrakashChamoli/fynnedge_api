<?php

namespace App\Filament\Resources\Documents\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('display_name')->label('Name'),
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('type')->badge(),
                TextEntry::make('status')->badge(),
                TextEntry::make('mime_type')->label('Content type'),
                TextEntry::make('size_bytes')->label('Size')->formatStateUsing(fn ($state): string => number_format((int) $state / 1024, 0).' KB'),
                TextEntry::make('created_at')->label('Uploaded')->dateTime(),
            ])->columns(2),
        ]);
    }
}
