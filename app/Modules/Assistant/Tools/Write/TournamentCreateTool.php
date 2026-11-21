<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TournamentFields;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Tournaments\Actions\CreateTournament;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class TournamentCreateTool extends ApprovableTool
{
    use TournamentFields;

    public static function toolName(): string
    {
        return 'tournament_create';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::Tournaments];
    }

    public function description(): string
    {
        return 'Legt ein Turnier als Entwurf an (nach Bestätigung durch die Orga auf einer Karte). '
            .'Event-ID aus events_list, Spiel-ID aus games_list; Zeiten in Europe/Berlin. '
            .'Nur mit vollständigen Angaben aufrufen, bei Unklarheit zuerst nachfragen.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['event_id' => $schema->integer()->description('Event-ID aus events_list.')->required()] + $this->fieldSchema($schema, true);
    }

    protected function precheck(array $arguments): ?string
    {
        $event = Event::query()->find((int) ($arguments['event_id'] ?? 0));

        if ($event === null) {
            return 'Not found: no event with this event_id (see events_list).';
        }

        if (! $this->can('create', Tournament::class)) {
            return 'Forbidden: this account may not create tournaments.';
        }

        [$data, $error] = $this->toActionInput($arguments);

        return $error ?? $this->validationError($data, partial: false);
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $event = Event::query()->find((int) ($arguments['event_id'] ?? 0));
        $format = TournamentFormat::tryFrom((string) ($arguments['format'] ?? ''));

        return new ApprovalPreview('Turnier anlegen', array_values(array_filter([
            ApprovalPreview::row('Event', $event->name ?? '#'.($arguments['event_id'] ?? '?')),
            ApprovalPreview::row('Name', $arguments['name'] ?? null),
            ApprovalPreview::row('Format', $format?->label() ?? ($arguments['format'] ?? null)),
            ApprovalPreview::row('Teamgröße', $arguments['team_size'] ?? null),
            ApprovalPreview::row('Start', $arguments['starts_at'] ?? null),
            isset($arguments['checkin_opens_at']) ? ApprovalPreview::row('Check-in öffnet', $arguments['checkin_opens_at']) : null,
            isset($arguments['checkin_closes_at']) ? ApprovalPreview::row('Check-in schließt', $arguments['checkin_closes_at']) : null,
            isset($arguments['max_entries']) ? ApprovalPreview::row('Max. Anmeldungen', $arguments['max_entries']) : null,
            isset($arguments['game_id']) ? ApprovalPreview::row('Spiel', Game::query()->find((int) $arguments['game_id'])->name ?? '#'.$arguments['game_id']) : null,
            isset($arguments['rules']) ? ApprovalPreview::row('Regeln', mb_substr((string) $arguments['rules'], 0, 200)) : null,
        ])));
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $event = Event::query()->findOrFail((int) $arguments['event_id']);
        [$data] = $this->toActionInput($arguments);

        $tournament = app(CreateTournament::class)->handle($this->actor, $event, $data);

        return ['created' => true, 'tournament_id' => $tournament->id, 'status' => $tournament->status->value];
    }
}
