<?php

namespace App\Filament\Resources\SupportCases\Tables;

use App\Domain\Support\SupportCaseStatus;
use App\Domain\Support\SupportPriority;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class SupportCasesTable
{
    /** @return array<string, string> */
    private static function statuses(): array
    {
        $out = [];

        foreach (SupportCaseStatus::cases() as $status) {
            $out[$status->value] = $status->label();
        }

        return $out;
    }

    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('reference')->searchable()->sortable(),
                TextColumn::make('user.mobile')->label('Customer')->searchable(),
                TextColumn::make('subject')->searchable()->limit(38),
                TextColumn::make('category')->badge()->toggleable(),

                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => SupportCaseStatus::from((string) $state)->label())
                    ->color(fn ($state): string => match (SupportCaseStatus::from((string) $state)) {
                        SupportCaseStatus::Resolved, SupportCaseStatus::Closed => 'success',
                        SupportCaseStatus::WaitingForCustomer => 'gray',
                        SupportCaseStatus::InProgress, SupportCaseStatus::Acknowledged => 'info',
                        SupportCaseStatus::Open => 'warning',
                    }),

                TextColumn::make('priority')
                    ->badge()
                    ->color(fn ($state): string => SupportPriority::from((string) $state) === SupportPriority::High ? 'danger' : 'gray'),

                TextColumn::make('last_customer_message_at')->label('Customer last wrote')->since()->placeholder('—'),
                TextColumn::make('created_at')->label('Opened')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(self::statuses()),
                SelectFilter::make('priority')->options(['normal' => 'Normal', 'high' => 'High']),
            ])
            ->recordActions([
                ViewAction::make(),
            ])
            ->toolbarActions([])
            ->defaultSort('id', 'desc');
    }
}
