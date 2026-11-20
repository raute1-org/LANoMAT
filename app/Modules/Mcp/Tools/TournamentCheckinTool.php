<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('tournament_checkin')]
#[Description('Check the caller\'s own tournament entry in. Works only inside the check-in window; the entry must already exist.')]
class TournamentCheckinTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'tournament_id' => $schema->integer()->description('Tournament id.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $checkIn = app(CheckInEntry::class);
        $tournament = Tournament::query()->findOrFail((int) $request->get('tournament_id'));
        abort_unless($tournament->isVisibleTo($this->user()), 404);

        $entry = TournamentEntry::query()
            ->where('tournament_id', $tournament->id)
            ->ownedBy($this->user())
            ->firstOrFail();

        $entry = $checkIn->handle($entry);

        return $this->structured([
            'entry' => [
                'id' => $entry->id,
                'tournament_id' => $tournament->id,
                'status' => $entry->status->value,
                'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
            ],
        ]);
    }
}
