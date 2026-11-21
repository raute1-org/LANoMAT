<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Tournaments\Actions\CreateTournament as CreateTournamentAction;
use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;

class CreateTournament extends CreateRecord
{
    protected static string $resource = TournamentResource::class;

    /**
     * One write path for new tournaments: the action authorizes and validates
     * (the same path the assistant uses).
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordCreation(array $data): Model
    {
        $actor = Auth::user();
        assert($actor instanceof User);

        return app(CreateTournamentAction::class)->handle(
            $actor,
            Event::query()->findOrFail($data['event_id']),
            Arr::except($data, ['event_id']),
        );
    }
}
