<?php

namespace App\Filament\Resources\KycVerifications\Schemas;

use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class KycVerificationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make()->schema([
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('status')->badge(),
                TextEntry::make('verification_type')->label('Type')->badge(),
                TextEntry::make('provider'),
                IconEntry::make('is_sample')->label('Sample data')->boolean(),
                TextEntry::make('failure_reason')->placeholder('—'),
                TextEntry::make('checked_at')->dateTime()->placeholder('—'),
                TextEntry::make('expires_at')->dateTime()->placeholder('—'),
            ])->columns(2),
        ]);
    }
}
