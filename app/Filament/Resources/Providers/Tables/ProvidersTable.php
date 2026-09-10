<?php

namespace App\Filament\Resources\Providers\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class ProvidersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('code')->searchable()->sortable(),
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state === 'active' ? 'success' : 'gray'),

                // Prominent on purpose: a fictional counterparty must never
                // be mistaken for a real one at a glance.
                IconColumn::make('is_sample')->label('Sample')->boolean(),

                TextColumn::make('products_count')->label('Products')->counts('products'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['active' => 'Active', 'retired' => 'Retired']),
                TernaryFilter::make('is_sample')->label('Sample'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([])
            ->defaultSort('name');
    }
}
