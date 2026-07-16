<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks\Pages;

use App\Modules\GameServers\Filament\Resources\ServerLinks\ServerLinkResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No CreateAction header action: server links are only ever produced by the
 * provisioning chain or the manual join-info fallback (see
 * ServerLinkResource's own comment).
 */
class ListServerLinks extends ListRecords
{
    protected static string $resource = ServerLinkResource::class;
}
