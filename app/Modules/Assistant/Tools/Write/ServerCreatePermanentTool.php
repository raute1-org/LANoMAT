<?php

declare(strict_types=1);

namespace App\Modules\Assistant\Tools\Write;

use App\Modules\Assistant\Support\ApprovalPreview;
use App\Modules\Assistant\Tools\ApprovableTool;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\CreatePermanentServer;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PresetAvailability;
use App\Support\Modules\Module;
use Illuminate\Contracts\JsonSchema\JsonSchema;

final class ServerCreatePermanentTool extends ApprovableTool
{
    public static function toolName(): string
    {
        return 'server_create_permanent';
    }

    /** @return list<Module> */
    public static function requiredModules(): array
    {
        return [Module::GameServers];
    }

    public function description(): string
    {
        return 'Legt einen dauerhaften Pelican-Server aus einem Preset an (nur Admins, nach Bestätigung auf einer Karte). '
            .'Game-ID und Preset-Key aus games_list.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'game_id' => $schema->integer()->description('Spiel-ID aus games_list.')->required(),
            'preset_key' => $schema->string()->description('Preset-Key aus games_list.')->required(),
            'title' => $schema->string()->description('Titel des Servers (höchstens 64 Zeichen).')->required(),
            'description' => $schema->string()->description('Optionale Beschreibung.'),
            'node_id' => $schema->string()->description('Optionale Pelican-Node-ID, sonst die Standard-Node.'),
        ];
    }

    protected function precheck(array $arguments): ?string
    {
        if (! $this->can('managePermanent', ServerLink::class)) {
            return 'Forbidden: only admins may create permanent servers.';
        }

        $game = Game::query()->find((int) ($arguments['game_id'] ?? 0));
        $preset = $game?->findPreset((string) ($arguments['preset_key'] ?? ''));

        if ($game === null || $preset === null) {
            return 'Not found: unknown game_id or preset_key (see games_list).';
        }

        if (trim((string) ($arguments['title'] ?? '')) === '') {
            return 'title: is required.';
        }

        return PresetAvailability::isAvailable($preset) ? null : (PresetAvailability::explanation($preset) ?? 'This preset is not available.');
    }

    public function preview(array $arguments): ApprovalPreview
    {
        $game = Game::query()->findOrFail((int) $arguments['game_id']);

        return new ApprovalPreview(__('assistant.card.title.create_permanent'), array_values(array_filter([
            ApprovalPreview::row(__('assistant.card.label.title'), $arguments['title'] ?? null),
            ApprovalPreview::row(__('assistant.card.label.game'), $game->name),
            ApprovalPreview::row(__('assistant.card.label.preset'), $game->findPreset((string) $arguments['preset_key'])->name ?? (string) $arguments['preset_key']),
            isset($arguments['description']) ? ApprovalPreview::row(__('assistant.card.label.description'), $arguments['description']) : null,
            isset($arguments['node_id']) ? ApprovalPreview::row(__('assistant.card.label.node'), $arguments['node_id'], true) : null,
        ])));
    }

    protected function execute(array $arguments, ?string $secret): array
    {
        $link = app(CreatePermanentServer::class)->pelican(
            $this->actor,
            Game::query()->findOrFail((int) $arguments['game_id']),
            (string) $arguments['preset_key'],
            (string) $arguments['title'],
            isset($arguments['description']) ? (string) $arguments['description'] : null,
            isset($arguments['node_id']) ? (string) $arguments['node_id'] : null,
        );

        return ['created' => true, 'server_id' => $link->id, 'status' => $link->status->value];
    }
}
