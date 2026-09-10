<?php

namespace App\Filament\Resources\CatalogueProducts\Schemas;

use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class CatalogueProductForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            // Identity, fixed. Applications record the product code and
            // public id they were quoted from; changing either would break
            // the link back from a customer's application to what it was.
            TextInput::make('public_id')->disabled()->dehydrated(false),
            TextInput::make('product_code')->disabled()->dehydrated(false),

            TextInput::make('display_name')->required()->maxLength(120),

            Select::make('category')
                ->options(['personal_loan' => 'Personal loan', 'business_loan' => 'Business loan', 'home_loan' => 'Home loan', 'loan_against_property' => 'Loan against property'])
                ->required()
                ->native(false),

            Select::make('status')
                ->options(['draft' => 'Draft', 'active' => 'Active', 'retired' => 'Retired'])
                ->required()
                ->native(false)
                ->helperText('Only active products are shown to customers.'),
        ]);
    }
}
