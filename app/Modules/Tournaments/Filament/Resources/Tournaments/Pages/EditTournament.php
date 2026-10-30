<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use App\Modules\Tournaments\Filament\Support\TournamentLifecycleActions;
use App\Modules\Tournaments\Models\Tournament;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...TournamentLifecycleActions::make(),
            DeleteAction::make()
                ->authorize('manage'),
        ];
    }

    /**
     * The current lifecycle status as a native Filament badge right under the
     * title; the next-step actions sit beside it in the header.
     */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var Tournament $record */
        $record = $this->getRecord();

        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$color">{{ $label }}</x-filament::badge>',
            ['color' => $record->status->color(), 'label' => $record->status->label()],
        ));
    }
}
