<?php

namespace App\Filament\Resources\LoanApplications\Tables;

use App\Domain\Application\ApplicationStatus;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoanApplicationsTable
{
    /** @return array<string, string> */
    public static function statusOptions(): array
    {
        $options = [];

        foreach (ApplicationStatus::cases() as $status) {
            $options[$status->value] = $status->label();
        }

        return $options;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),

                TextColumn::make('user.mobile')
                    ->label('Customer')
                    ->searchable()
                    ->url(fn ($record): ?string => $record->user_id
                        ? route('filament.admin.resources.users.view', ['record' => $record->user_id])
                        : null),

                TextColumn::make('product_name')->label('Product')->searchable()->limit(28),

                TextColumn::make('amount')->money('INR')->sortable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => ApplicationStatus::from((string) $state)->label())
                    ->color(fn ($state): string => match (ApplicationStatus::from((string) $state)) {
                        ApplicationStatus::Approved, ApplicationStatus::Disbursed => 'success',
                        ApplicationStatus::Declined, ApplicationStatus::Cancelled => 'danger',
                        ApplicationStatus::Submitted,
                        ApplicationStatus::UnderReview,
                        ApplicationStatus::ActionNeeded => 'warning',
                        ApplicationStatus::Started => 'gray',
                    }),

                // Fixture and simulated submissions must never be mistaken
                // for something a real lender answered.
                IconColumn::make('is_simulated')->label('Simulated')->boolean(),

                TextColumn::make('created_at')->label('Started')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statusOptions()),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
