<?php

namespace App\Filament\Resources\AdminUsers\Schemas;

use App\Domain\Admin\AdminRole;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Hash;

class AdminUserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(120),

            TextInput::make('email')
                ->email()
                ->required()
                ->maxLength(190)
                // The login identity, so the form has to answer the same
                // way the unique index does rather than let the insert fail.
                ->unique(ignoreRecord: true),

            TextInput::make('password')
                ->password()
                ->revealable(false)
                // Twelve, not a zoo of character classes. Length is what
                // actually resists guessing, and rules that forbid the
                // password somebody would remember produce the one on the
                // sticky note.
                ->minLength(12)
                ->maxLength(255)
                ->helperText('At least 12 characters.')
                ->required(fn (string $operation): bool => $operation === 'create')
                // Left blank on edit means "leave it alone", so an unrelated
                // change cannot silently rewrite somebody's password.
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->dehydrateStateUsing(fn (string $state): string => Hash::make($state))
                // Never populated from the record: there is nothing to
                // populate it with, and a form field holding a hash is one
                // submit away from storing the hash as the new password.
                ->autocomplete('new-password'),

            Select::make('role')
                ->options(AdminRole::options())
                ->required()
                ->native(false),

            Toggle::make('is_active')
                ->label('Active')
                ->default(true)
                ->helperText('An inactive account cannot sign in, and any session it already has stops working.'),
        ]);
    }
}
