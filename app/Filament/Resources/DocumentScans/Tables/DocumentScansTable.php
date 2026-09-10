<?php

namespace App\Filament\Resources\DocumentScans\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class DocumentScansTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('document_id')->label('Document')->sortable(),
                TextColumn::make('status')->badge()->color(fn ($state): string => match ($state) {
                    'completed' => 'success',
                    'failed' => 'danger',
                    'needs_review' => 'warning',
                    default => 'gray',
                }),
                TextColumn::make('family')->badge()->placeholder('—'),
                IconColumn::make('is_sample')->label('Sample')->boolean(),
                TextColumn::make('completed_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['completed' => 'Completed', 'failed' => 'Failed', 'needs_review' => 'Needs review', 'processing' => 'Processing', 'queued' => 'Queued', 'consent_required' => 'Consent required', 'unavailable' => 'Unavailable', 'not_started' => 'Not started']),
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
