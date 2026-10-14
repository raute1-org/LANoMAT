<?php

use App\Providers\AppServiceProvider;
use App\Providers\Filament\AdminPanelProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\ModulesServiceProvider;

return [
    ModulesServiceProvider::class,
    AppServiceProvider::class,
    AdminPanelProvider::class,
    FortifyServiceProvider::class,
];
