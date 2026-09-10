<?php

namespace App\Filament\Resources\SupportCases\Schemas;

use App\Domain\Support\SupportCaseStatus;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class SupportCaseInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Case')->schema([
                TextEntry::make('reference'),
                TextEntry::make('user.mobile')->label('Customer'),
                TextEntry::make('category')->badge(),
                TextEntry::make('status')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => SupportCaseStatus::from((string) $state)->label()),
                TextEntry::make('priority')->badge(),
                TextEntry::make('created_at')->label('Opened')->dateTime(),
            ])->columns(3),

            Section::make('What the customer asked')->schema([
                TextEntry::make('subject'),
                TextEntry::make('description')->columnSpanFull(),
            ]),

            /*
             * What the case was opened about, carried from the screen the
             * customer was on. Shown as a label rather than a link: the
             * context may be an application an advisor is not allowed to
             * open, and the policy on that resource is what decides.
             */
            Section::make('Context')
                ->visible(fn ($record): bool => filled($record->context_type))
                ->schema([
                    TextEntry::make('context_type')->label('About')->badge(),
                    TextEntry::make('context_reference')->label('Reference')->placeholder('—'),
                    TextEntry::make('context_product_name')->label('Product')->placeholder('—'),
                    TextEntry::make('context_status_label')->label('Status at the time')->placeholder('—'),
                ])->columns(2),

            Section::make('Resolution')
                ->visible(fn ($record): bool => $record->resolved_at !== null || $record->closed_at !== null)
                ->schema([
                    TextEntry::make('resolved_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_at')->dateTime()->placeholder('—'),
                    TextEntry::make('closed_reason')->placeholder('—'),
                ])->columns(3),
        ]);
    }
}
