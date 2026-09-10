<?php

namespace App\Filament\Resources\KycVerifications\Pages;

use App\Filament\Resources\KycVerifications\KycVerificationResource;
use Filament\Resources\Pages\ListRecords;

class ListKycVerifications extends ListRecords
{
    protected static string $resource = KycVerificationResource::class;

    protected function getHeaderActions(): array
    {
        return [];
    }
}
