<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No header "create" action: users are created via registration/Discord
 * OAuth, never by orga staff in the admin panel.
 */
class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;
}
