<?php

namespace App\Filament\Resources\KycVerifications\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class KycVerificationsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('verification_type')->label('Type')->badge(),
                TextColumn::make('status')->badge()->color(fn ($state): string => match ($state) {
                    'verified' => 'success',
                    'not_verified', 'failed', 'expired' => 'danger',
                    'needs_review' => 'warning',
                    default => 'gray',
                }),
                IconColumn::make('is_sample')->label('Sample')->boolean(),
                TextColumn::make('checked_at')->dateTime()->placeholder('—')->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(['verified' => 'Verified', 'not_verified' => 'Not verified', 'needs_review' => 'Needs review', 'failed' => 'Failed', 'expired' => 'Expired', 'processing' => 'Processing', 'consent_required' => 'Consent required', 'unavailable' => 'Unavailable', 'not_started' => 'Not started']),
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
