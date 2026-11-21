<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Filament\Resources\AssistantActions\Pages;

use App\Modules\Assistant\Filament\Resources\AssistantActions\AssistantActionResource;
use Filament\Resources\Pages\ListRecords;

class ListAssistantActions extends ListRecords
{
    protected static string $resource = AssistantActionResource::class;
}
