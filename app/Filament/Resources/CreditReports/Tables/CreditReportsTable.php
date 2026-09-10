<?php

namespace App\Filament\Resources\CreditReports\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CreditReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('score')->sortable()->badge()->color(fn ($record): string => match (true) {
                    $record->score === null => 'gray',
                    $record->score >= 750 => 'success',
                    $record->score >= 650 => 'warning',
                    default => 'danger',
                }),
                TextColumn::make('score_model')->label('Model')->toggleable(),
                TextColumn::make('bureau')->badge(),
                TextColumn::make('obtained_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('bureau')->options(['fixture' => 'Fixture']),
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
