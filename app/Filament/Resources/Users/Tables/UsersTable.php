<?php

namespace App\Filament\Resources\Users\Tables;

use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class UsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('id')->label('ID')->sortable(),

                TextColumn::make('mobile')->searchable()->sortable(),

                TextColumn::make('customerProfile.full_name')
                    ->label('Name')
                    ->searchable()
                    ->placeholder('—'),

                TextColumn::make('customerProfile.city')->label('City')->searchable()->placeholder('—'),

                TextColumn::make('applications_count')
                    ->label('Applications')
                    ->counts('applications')
                    ->sortable(),

                TextColumn::make('created_at')->label('Registered')->dateTime()->sortable(),

                TextColumn::make('last_login_at')->dateTime()->placeholder('Never')->sortable(),
            ])
            ->filters([
                Filter::make('onboarded')
                    ->label('Onboarding complete')
                    ->query(fn (Builder $query): Builder => $query->whereHas(
                        'customerProfile',
                        fn (Builder $q) => $q->whereNotNull('onboarding_completed_at'),
                    )),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            // Read-only: no bulk actions, so there is no path to deleting a
            // customer and the records that explain their applications.
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
