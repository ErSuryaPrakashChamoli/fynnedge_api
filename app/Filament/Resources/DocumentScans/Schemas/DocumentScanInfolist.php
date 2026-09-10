<?php

namespace App\Filament\Resources\DocumentScans\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class DocumentScanInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('status')->badge(),
                TextEntry::make('family')->badge()->placeholder('—'),
                TextEntry::make('classified_as')->placeholder('—'),
                TextEntry::make('provider')->placeholder('—'),
                IconEntry::make('is_sample')->label('Sample data')->boolean(),
                TextEntry::make('failure_reason')->placeholder('—'),
                TextEntry::make('started_at')->dateTime()->placeholder('—'),
                TextEntry::make('completed_at')->dateTime()->placeholder('—'),
            ])->columns(2),
        ]);
    }
}
