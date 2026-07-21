<?php

declare(strict_types=1);

namespace App\Modules\News\Filament\Resources\NewsPosts\Pages;

use App\Modules\News\Filament\Resources\NewsPosts\NewsPostResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditNewsPost extends EditRecord
{
    protected static string $resource = NewsPostResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
