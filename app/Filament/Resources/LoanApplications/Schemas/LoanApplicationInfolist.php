<?php

namespace App\Filament\Resources\LoanApplications\Schemas;

use App\Domain\Application\ApplicationStatus;
use Filament\Infolists\Components\IconEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class LoanApplicationInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Application')->schema([
                TextEntry::make('reference'),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ApplicationStatus::from((string) $state)->label()),
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('product_name')->label('Product'),
                TextEntry::make('lender'),
                IconEntry::make('is_simulated')->label('Simulated')->boolean(),
            ])->columns(2),

            Section::make('Terms as quoted')->schema([
                TextEntry::make('amount')->money('INR'),
                TextEntry::make('tenure_months')->label('Tenure (months)'),
                TextEntry::make('interest_rate')->label('Interest rate')->suffix('%'),
                TextEntry::make('emi')->label('EMI')->money('INR'),
                TextEntry::make('total_payable')->money('INR'),
                TextEntry::make('processing_fee')->money('INR'),
            ])->columns(3),

            Section::make('Timeline')->schema([
                TextEntry::make('created_at')->label('Started')->dateTime(),
                TextEntry::make('submitted_at')->dateTime()->placeholder('Not submitted'),
                TextEntry::make('cancelled_at')->dateTime()->placeholder('—'),
            ])->columns(3),
        ]);
    }
}
