<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\StartServerFromPreset;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PresetAvailability;
use App\Modules\GameServers\Support\PresetOverrides;
use App\Support\Modules\Module;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ServerStartPresetTool extends ApprovableTool
{
    /** Overrides must never carry secrets; those are not the model's business. */
    private const SECRET_KEY = '/pass(word|wd)?|secret|token|api[_-]?key|gslt|authkey/i';

    public static function toolName(): string
    {
        return 'server_start_preset';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Startet einen Event-Server aus dem Preset eines Spiels (nach Bestätigung auf einer Karte). '
            .'Event-ID aus events_list, Game-ID und Preset-Key aus games_list (nur verfügbare Presets). '
            .'Overrides sind optional und nie für Passwörter oder Schlüssel.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'event_id' => $schema->integer()->description('Event-ID aus events_list.')->required(),
            'game_id' => $schema->integer()->description('Spiel-ID aus games_list.')->required(),
            'preset_key' => $schema->string()->description('Preset-Key aus games_list.')->required(),
            'title' => $schema->string()->description('Optionaler Titel des Servers.'),
            'overrides' => $schema->array()->description('Optionale Preset-Überschreibungen als Liste von key/value.')
                ->items($schema->object(['key' => $schema->string()->required(), 'value' => $schema->string()->required()])),
        ];
    }

    protected function precheck(array $arguments): ?string
    {
        $event = Event::query()->find((int) ($arguments['event_id'] ?? 0));
        $game = Game::query()->find((int) ($arguments['game_id'] ?? 0));

        if ($event === null || $game === null) {
            return 'Not found: unknown event_id or game_id (see events_list and games_list).';
        }

        if (! $this->can('start', ServerLink::class)) {
            return 'Forbidden: this account may not start servers.';
        }

        $preset = $game->findPreset((string) ($arguments['preset_key'] ?? ''));

        if ($preset === null) {
            return 'Not found: this game has no preset with that preset_key (see games_list).';
        }

        if (! PresetAvailability::isAvailable($preset)) {
            return PresetAvailability::explanation($preset) ?? 'This preset is not available.';
        }

        try {
            $overrides = $this->overrides($arguments);
            PresetOverrides::apply($preset, $overrides);
        } catch (DomainException $exception) {
            return $this->domainMessage($exception);
        }

        return null;
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $event = Event::query()->findOrFail((int) $arguments['event_id']);
        $game = Game::query()->findOrFail((int) $arguments['game_id']);
        $preset = $game->findPreset((string) $arguments['preset_key']);
        $rows = [
            ApprovalPreview::row(__('assistant.card.label.event'), $event->name),
            ApprovalPreview::row(__('assistant.card.label.game'), $game->name),
            ApprovalPreview::row(__('assistant.card.label.preset'), $preset->name ?? (string) $arguments['preset_key']),
        ];

        if (isset($arguments['title']) && $arguments['title'] !== '') {
            $rows[] = ApprovalPreview::row(__('assistant.card.label.title'), $arguments['title']);
        }

        foreach ($this->overrides($arguments) as $key => $value) {
            $rows[] = ApprovalPreview::row($key, $value, true);
        }

        return new ApprovalPreview(__('assistant.card.title.start_preset'), $rows);
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = app(StartServerFromPreset::class)->handle(
            Event::query()->findOrFail((int) $arguments['event_id']),
            Game::query()->findOrFail((int) $arguments['game_id']),
            (string) $arguments['preset_key'],
            isset($arguments['title']) ? (string) $arguments['title'] : null,
            $this->overrides($arguments),
            $this->actor,
        );

        return ['started' => true, 'server_id' => $link->id, 'status' => $link->status->value];
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, string>
     *
     * @throws DomainException on a secret-looking key
     */
    private function overrides(array $arguments): array
    {
        $out = [];

        foreach (is_array($arguments['overrides'] ?? null) ? $arguments['overrides'] : [] as $pair) {
            if (! is_array($pair) || ! isset($pair['key']) || ! is_scalar($pair['key'])) {
                continue;
            }

            if (preg_match(self::SECRET_KEY, (string) $pair['key']) === 1) {
                throw new DomainException(__('assistant.password_via_card'));
            }

            $out[(string) $pair['key']] = is_scalar($pair['value'] ?? null) ? (string) $pair['value'] : '';
        }

        return $out;
    }
}
