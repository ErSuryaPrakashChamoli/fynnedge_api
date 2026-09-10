<?php

namespace App\Filament\Resources\Providers\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;

class ProviderForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('code')
                ->required()
                ->maxLength(40)
                ->unique(ignoreRecord: true)
                // The stable identity products and events resolve against,
                // so it is fixed once the provider exists.
                ->disabledOn('edit')
                ->helperText('Cannot be changed once created — events and products resolve against it.'),

            TextInput::make('name')->required()->maxLength(120),

            Select::make('type')
                ->options(['bank' => 'Bank', 'nbfc' => 'NBFC', 'fintech' => 'Fintech', 'bureau' => 'Bureau', 'other' => 'Other'])
                ->required()
                ->native(false),

            Select::make('status')
                ->options(['active' => 'Active', 'retired' => 'Retired'])
                ->default('active')
                ->required()
                ->native(false),

            Toggle::make('is_sample')
                ->label('Sample provider')
                ->default(true)
                ->helperText('Sample providers are fictional. Turning this off asserts that this is a real counterparty.'),
        ]);
    }
}
