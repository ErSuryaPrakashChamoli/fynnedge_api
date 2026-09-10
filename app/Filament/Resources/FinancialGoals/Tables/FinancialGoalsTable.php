<?php

namespace App\Filament\Resources\FinancialGoals\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class FinancialGoalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('title')->searchable()->limit(30),
                TextColumn::make('category')->badge(),
                TextColumn::make('target_amount')->money('INR')->sortable(),
                TextColumn::make('saved_amount')->money('INR')->sortable(),
                TextColumn::make('target_date')->date()->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state === 'active' ? 'success' : 'gray'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'achieved' => 'Achieved', 'archived' => 'Archived']),
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
