<?php

namespace App\Filament\Resources\CatalogueProducts\Tables;

use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class CatalogueProductsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('public_id')->label('Public ID')->searchable(),
                TextColumn::make('display_name')->label('Name')->searchable()->sortable(),
                TextColumn::make('provider.name')->label('Provider')->searchable(),
                TextColumn::make('category')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state): string => match ($state) {
                    'active' => 'success',
                    'draft' => 'warning',
                    default => 'gray',
                }),
                IconColumn::make('provider.is_sample')->label('Sample')->boolean(),
                TextColumn::make('versions_count')->label('Versions')->counts('versions'),
            ])
            ->filters([
                SelectFilter::make('status')->options(['draft' => 'Draft', 'active' => 'Active', 'retired' => 'Retired']),
                SelectFilter::make('provider')->relationship('provider', 'name'),
            ])
            ->recordActions([EditAction::make()])
            ->toolbarActions([])
            ->defaultSort('display_name');
    }
}
