<?php

namespace App\Filament\Resources\Documents\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('display_name')->label('Name')->searchable()->limit(36),
                TextColumn::make('type')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state): string => $state === 'uploaded' ? 'success' : 'danger'),
                TextColumn::make('size_bytes')->label('Size')->formatStateUsing(fn ($state): string => number_format((int) $state / 1024, 0).' KB')->sortable(),
                TextColumn::make('mime_type')->label('Type')->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('created_at')->label('Uploaded')->dateTime()->sortable(),
                TextColumn::make('deleted_at')->label('Deleted')->dateTime()->placeholder('—')->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('type')->options(['identity' => 'Identity', 'income' => 'Income', 'bank' => 'Bank', 'loan' => 'Loan', 'property' => 'Property', 'other' => 'Other']),
                SelectFilter::make('status')->options(['uploaded' => 'Uploaded', 'failed' => 'Failed']),
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
