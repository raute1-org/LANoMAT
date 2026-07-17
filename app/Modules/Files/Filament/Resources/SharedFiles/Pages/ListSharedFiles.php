<?php

declare(strict_types=1);

namespace App\Modules\Files\Filament\Resources\SharedFiles\Pages;

use App\Modules\Files\Filament\Resources\SharedFiles\SharedFileResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No CreateAction header action: shared files are only ever produced by
 * participant uploads (UploadSharedFile), never by hand in the admin panel
 * (see SharedFileResource's own comment).
 */
class ListSharedFiles extends ListRecords
{
    protected static string $resource = SharedFileResource::class;
}
