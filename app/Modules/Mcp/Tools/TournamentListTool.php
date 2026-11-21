<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Tools;

use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('tournament_list')]
#[Description('List the tournaments of an event with status, format and the caller\'s entry (if any).')]
#[IsReadOnly]
class TournamentListTool extends LanomatTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'event_slug' => $schema->string()->description('Event slug.')->required(),
        ];
    }

    protected function run(Request $request): Response|ResponseFactory
    {
        $event = $this->eventFromSlug((string) $request->get('event_slug'));
        $user = $this->user();

        $tournaments = Tournament::query()
            ->where('event_id', $event->id)
            ->visibleTo($user)
            ->with('game:id,name,slug')
            ->orderBy('starts_at')
            ->get();

        $entries = TournamentEntry::query()
            ->whereIn('tournament_id', $tournaments->pluck('id'))
            ->where(fn ($query) => $query->where('user_id', $user->id)->orWhereHas(
                'team',
                fn ($team) => $team->whereHas('members', fn ($members) => $members->where('user_id', $user->id)),
            ))
            ->get()
            ->keyBy('tournament_id');

        return $this->structured([
            'tournaments' => $tournaments->map(static function (Tournament $tournament) use ($entries): array {
                $entry = $entries->get($tournament->id);

                return [
                    'id' => $tournament->id,
                    'name' => $tournament->name,
                    'format' => $tournament->format->value,
                    'status' => $tournament->status->value,
                    'game' => $tournament->game?->name,
                    'team_size' => $tournament->team_size,
                    'max_entries' => $tournament->max_entries,
                    'starts_at' => $tournament->starts_at->toIso8601String(),
                    'checkin_opens_at' => $tournament->checkin_opens_at?->toIso8601String(),
                    'checkin_closes_at' => $tournament->checkin_closes_at?->toIso8601String(),
                    'my_entry' => $entry === null ? null : [
                        'id' => $entry->id,
                        'status' => $entry->status->value,
                        'checked_in_at' => $entry->checked_in_at?->toIso8601String(),
                    ],
                ];
            })->all(),
        ]);
    }
}
