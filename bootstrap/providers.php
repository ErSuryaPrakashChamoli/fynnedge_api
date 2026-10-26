<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\OpenApiServiceProvider;

return [
    AppServiceProvider::class,
    AdminPanelProvider::class,
    OpenApiServiceProvider::class,
];
