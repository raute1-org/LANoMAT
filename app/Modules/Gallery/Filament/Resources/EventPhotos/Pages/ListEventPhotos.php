<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Filament\Resources\EventPhotos\Pages;

use App\Modules\Gallery\Filament\Resources\EventPhotos\EventPhotoResource;
use Filament\Resources\Pages\ListRecords;

/**
 * No CreateAction header action: event photos are only ever produced by
 * participant uploads (UploadPhoto), never by hand in the admin panel (see
 * EventPhotoResource's own comment).
 */
class ListEventPhotos extends ListRecords
{
    protected static string $resource = EventPhotoResource::class;
}
