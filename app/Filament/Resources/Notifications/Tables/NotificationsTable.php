<?php

namespace App\Filament\Resources\Notifications\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class NotificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('title')->searchable()->limit(40),
                TextColumn::make('category')->badge(),
                TextColumn::make('type')->toggleable(isToggledHiddenByDefault: true),
                IconColumn::make('read_at')->label('Read')->boolean()->getStateUsing(fn ($record): bool => $record->read_at !== null),
                TextColumn::make('created_at')->label('Sent')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('category')->options(['application' => 'Application', 'document' => 'Document', 'financial_health' => 'Financial health', 'goal' => 'Goal', 'system' => 'System']),
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
