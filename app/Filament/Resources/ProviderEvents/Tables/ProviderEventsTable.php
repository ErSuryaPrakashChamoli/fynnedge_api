<?php

namespace App\Filament\Resources\ProviderEvents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ProviderEventsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider_code')->label('Provider')->badge()->searchable(),
                TextColumn::make('event_type')->label('Event')->searchable(),
                TextColumn::make('processing_status')->label('Processing')->badge()->color(fn ($state): string => match ($state) {
                    'processed' => 'success',
                    'failed' => 'danger',
                    default => 'gray',
                }),
                TextColumn::make('loan_application_id')->label('Application')->placeholder('—'),
                IconColumn::make('is_simulated')->label('Simulated')->boolean(),
                TextColumn::make('retry_count')->label('Retries')->sortable(),
                TextColumn::make('occurred_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('processing_status')->label('Processing')->options(['processed' => 'Processed', 'failed' => 'Failed', 'ignored' => 'Ignored']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Read-only: no bulk actions, so there is no path from this
            // screen to deleting a customer's record.
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
