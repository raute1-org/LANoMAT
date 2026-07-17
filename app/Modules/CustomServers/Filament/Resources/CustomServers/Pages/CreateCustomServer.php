<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers\Pages;

use App\Modules\CustomServers\Filament\Resources\CustomServers\CustomServerResource;
use Filament\Resources\Pages\CreateRecord;

class CreateCustomServer extends CreateRecord
{
    protected static string $resource = CustomServerResource::class;
}
