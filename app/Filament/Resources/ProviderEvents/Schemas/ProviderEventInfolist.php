<?php

namespace App\Filament\Resources\ProviderEvents\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ProviderEventInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('provider_code')->label('Provider')->badge(),
                TextEntry::make('event_type')->label('Event'),
                TextEntry::make('processing_status')->badge(),
                TextEntry::make('outcome_reason')->placeholder('—'),
                TextEntry::make('reason_code')->placeholder('—'),
                IconEntry::make('is_simulated')->label('Simulated')->boolean(),
                TextEntry::make('retry_count')->label('Retries'),
                TextEntry::make('occurred_at')->dateTime(),
                TextEntry::make('received_at')->dateTime(),
                TextEntry::make('processed_at')->dateTime()->placeholder('—'),
            ])->columns(2),
        ]);
    }
}
