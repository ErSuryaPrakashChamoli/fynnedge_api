<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\AdminUser;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Illuminate\Support\Facades\Auth;

class UserInfolist
{
    /** Whether the signed-in administrator may read a customer's money. */
    private static function seesMoney(): bool
    {
        $admin = Auth::guard('admin')->user();

        return $admin instanceof AdminUser && $admin->role()->seesFinancialDetail();
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Customer')->schema([
                TextEntry::make('id')->label('ID'),
                TextEntry::make('mobile'),
                TextEntry::make('customerProfile.full_name')->label('Name')->placeholder('—'),
                TextEntry::make('customerProfile.city')->label('City')->placeholder('—'),
                TextEntry::make('customerProfile.email')->label('Email')->placeholder('—'),
                TextEntry::make('created_at')->dateTime()->label('Registered'),
                TextEntry::make('last_login_at')->dateTime()->placeholder('Never'),
            ])->columns(2),

            /*
             * The customer's finances, shown only to the roles allowed to
             * read them.
             *
             * Hidden rather than masked: a masked figure still says a figure
             * exists and roughly how large it is, and an Advisor helping
             * with a document does not need to know what the customer earns.
             */
            Section::make('Financial profile')
                ->visible(fn (): bool => self::seesMoney())
                ->schema([
                    TextEntry::make('financialProfile.monthly_income')->label('Monthly income')->money('INR')->placeholder('—'),
                    TextEntry::make('financialProfile.monthly_expenses')->label('Monthly expenses')->money('INR')->placeholder('—'),
                    TextEntry::make('financialProfile.existing_emi')->label('Existing EMI')->money('INR')->placeholder('—'),
                    TextEntry::make('financialProfile.savings')->label('Savings')->money('INR')->placeholder('—'),
                    TextEntry::make('financialProfile.employment_type')->label('Employment')->badge()->placeholder('—'),
                ])->columns(2),
        ]);
    }
}
