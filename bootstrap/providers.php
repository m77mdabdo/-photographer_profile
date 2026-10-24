<?php

use App\Providers\ActivityLogServiceProvider;
use App\Providers\AppServiceProvider;
use App\Providers\Filament\StudioPanelProvider;
use App\Providers\FortifyServiceProvider;

return [
    ActivityLogServiceProvider::class,
    AppServiceProvider::class,
    StudioPanelProvider::class,
    FortifyServiceProvider::class,
];
