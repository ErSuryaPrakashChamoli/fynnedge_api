<?php

namespace App\Filament\Resources\Consents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class ConsentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('type')->badge()->searchable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state === 'granted' ? 'success' : 'gray'),
                TextColumn::make('version'),
                TextColumn::make('source')->badge()->toggleable(),
                TextColumn::make('granted_at')->dateTime()->placeholder('—')->sortable(),
                TextColumn::make('withdrawn_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['granted' => 'Granted', 'withdrawn' => 'Withdrawn']),
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
