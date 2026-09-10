<?php

namespace App\Filament\Resources\ProviderEvents\Pages;

use App\Filament\Resources\ProviderEvents\ProviderEventResource;
use Filament\Resources\Pages\ViewRecord;

class ViewProviderEvent extends ViewRecord
{
    protected static string $resource = ProviderEventResource::class;
}
