<?php

namespace App\Filament\Resources\AdminUsers\Tables;

use App\Domain\Admin\AdminRole;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class AdminUsersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),

                TextColumn::make('email')->searchable()->sortable(),

                TextColumn::make('role')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state instanceof AdminRole
                        ? $state->label()
                        : AdminRole::from((string) $state)->label())
                    ->color(fn ($state): string => match ($state instanceof AdminRole ? $state : AdminRole::from((string) $state)) {
                        AdminRole::SuperAdmin => 'danger',
                        AdminRole::BusinessHead => 'warning',
                        AdminRole::Manager, AdminRole::TeamLeader => 'info',
                        AdminRole::Advisor => 'success',
                        AdminRole::Viewer => 'gray',
                    }),

                IconColumn::make('is_active')->label('Active')->boolean(),

                TextColumn::make('last_login_at')
                    ->label('Last signed in')
                    ->dateTime()
                    ->placeholder('Never')
                    ->sortable(),

                TextColumn::make('created_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('role')->options(AdminRole::options()),
                TernaryFilter::make('is_active')->label('Active'),
            ])
            ->recordActions([
                EditAction::make(),
            ])
            // No delete, in bulk or otherwise. Staff are deactivated, never
            // removed, so the records carrying their name keep making sense.
            ->toolbarActions([])
            ->defaultSort('name');
    }
}
