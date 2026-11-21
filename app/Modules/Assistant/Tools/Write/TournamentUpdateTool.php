<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Assistant\Tools\Write\Concerns\TournamentFields;
use App\Modules\Tournaments\Actions\UpdateTournament;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\DisplayTimezone;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class TournamentUpdateTool extends ApprovableTool
{
    use TournamentFields;

    public static function toolName(): string
    {
        return 'tournament_update';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::Tournaments];
    }

    public function description(): string
    {
        return 'Ändert Einstellungen eines Turniers im Status Entwurf oder Angekündigt (nach Bestätigung auf einer Karte), '
            .'zum Beispiel Spiel, max. Anmeldungen oder Check-in-Fenster. Nur die zu ändernden Felder angeben; Zeiten in Europe/Berlin.';
    }

    public function schema(JsonSchema $schema): array
    {
        return ['tournament_id' => $schema->integer()->description('ID aus tournaments_list.')->required()] + $this->fieldSchema($schema, false);
    }

    protected function precheck(array $arguments): ?string
    {
        $tournament = Tournament::query()->find((int) ($arguments['tournament_id'] ?? 0));

        if ($tournament === null) {
            return 'Not found: no tournament with this tournament_id (see tournaments_list).';
        }

        if (! $this->can('update', $tournament)) {
            return 'Forbidden: this account may not edit this tournament.';
        }

        if (! in_array($tournament->status, [TournamentStatus::Draft, TournamentStatus::Announced], true)) {
            return __('tournaments.errors.not_editable');
        }

        [$data, $error] = $this->toActionInput($arguments);

        if ($error !== null) {
            return $error;
        }

        return $data === [] ? 'No changes given: pass at least one field to change.' : $this->validationError($data, partial: true);
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $tournament = Tournament::query()->findOrFail((int) $arguments['tournament_id']);
        $rows = [ApprovalPreview::row(__('assistant.card.label.tournament'), $tournament->name)];

        $current = [
            'name' => $tournament->name,
            'format' => $tournament->format->value,
            'team_size' => $tournament->team_size,
            'starts_at' => DisplayTimezone::local($tournament->starts_at)->format('Y-m-d H:i'),
            'game_id' => $tournament->game_id,
            'max_entries' => $tournament->max_entries,
            'checkin_opens_at' => $tournament->checkin_opens_at === null ? null : DisplayTimezone::local($tournament->checkin_opens_at)->format('Y-m-d H:i'),
            'checkin_closes_at' => $tournament->checkin_closes_at === null ? null : DisplayTimezone::local($tournament->checkin_closes_at)->format('Y-m-d H:i'),
            'rules' => $tournament->rules === null ? null : mb_substr($tournament->rules, 0, 100),
            'require_checkin' => $tournament->settings['require_checkin'] ?? true,
        ];
        $labels = ['name', 'format', 'team_size', 'starts_at', 'game_id', 'max_entries', 'checkin_opens_at', 'checkin_closes_at', 'rules', 'require_checkin'];

        foreach ($labels as $field) {
            $label = __('assistant.card.label.'.$field);
            if (! array_key_exists($field, $arguments)) {
                continue;
            }

            $old = $current[$field] ?? null;
            $new = $field === 'rules' ? mb_substr((string) $arguments[$field], 0, 100) : $arguments[$field];
            $rows[] = ApprovalPreview::row($label, ($old === null || $old === '' ? '—' : (is_bool($old) ? __('assistant.card.'.($old ? 'yes' : 'no')) : (string) $old)).' → '.(is_bool($new) ? __('assistant.card.'.($new ? 'yes' : 'no')) : (string) $new));
        }

        return new ApprovalPreview(__('assistant.card.title.tournament_update'), $rows);
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $tournament = Tournament::query()->findOrFail((int) $arguments['tournament_id']);
        [$data] = $this->toActionInput($arguments);

        if (isset($data['settings'])) {
            $data['settings'] = [...($tournament->settings), ...$data['settings']];
        }

        $updated = app(UpdateTournament::class)->handle($this->actor, $tournament, $data);

        return ['updated' => true, 'tournament_id' => $updated->id];
    }
}
