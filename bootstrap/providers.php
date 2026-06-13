<?php

use App\Providers\AppServiceProvider;
use App\Providers\DeliverabilityServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ModuleServiceProvider;
use App\Providers\SettingsServiceProvider;
use App\Providers\ThemeServiceProvider;

return [
    AppServiceProvider::class,
    DeliverabilityServiceProvider::class,
    FortifyServiceProvider::class,
    ModuleServiceProvider::class,
    SettingsServiceProvider::class,
    ThemeServiceProvider::class,
];
