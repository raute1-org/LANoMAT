<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks\Pages;

use App\Models\User;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\StartServerFromPreset;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Filament\Resources\ServerLinks\ServerLinkResource;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PresetAvailability;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Server links are produced by the provisioning chain, the manual join-info
 * fallback, and the orga's "Server aus Preset starten" header action below (an
 * event-level server from a game preset, e.g. the LAN's TTT server).
 */
class ListServerLinks extends ListRecords
{
    protected static string $resource = ServerLinkResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startFromPreset')
                ->label(__('gameservers.start_preset.action'))
                ->icon('heroicon-o-rocket-launch')
                ->authorize(fn (): bool => Auth::user()?->can('start', ServerLink::class) ?? false)
                ->modalHeading(__('gameservers.start_preset.heading'))
                ->modalDescription(__('gameservers.start_preset.description'))
                ->modalSubmitActionLabel(__('gameservers.start_preset.submit'))
                ->schema([
                    Select::make('event_id')
                        ->label(__('gameservers.start_preset.event'))
                        ->options(fn (): array => Event::query()->orderByDesc('starts_at')->pluck('name', 'id')->all())
                        ->searchable()
                        ->required(),
                    Select::make('game_id')
                        ->label(__('gameservers.start_preset.game'))
                        ->options(fn (): array => self::gamesWithPresets())
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('preset_key', null))
                        ->required(),
                    Select::make('preset_key')
                        ->label(__('gameservers.start_preset.preset'))
                        ->options(fn (Get $get): array => self::presetOptions($get('game_id')))
                        ->disableOptionWhen(fn (string $value, Get $get): bool => self::presetLocked($get('game_id'), $value))
                        ->live()
                        ->required(),
                    Callout::make(fn (Get $get): string => self::presetNote($get('game_id'), $get('preset_key')))
                        ->visible(fn (Get $get): bool => self::presetNote($get('game_id'), $get('preset_key')) !== ''),
                    TextInput::make('title')
                        ->label(__('gameservers.start_preset.title'))
                        ->helperText(__('gameservers.start_preset.title_help'))
                        ->maxLength(64),
                    Fieldset::make(__('gameservers.start_preset.overrides'))
                        ->schema(fn (Get $get): array => self::overrideFields($get('game_id'), $get('preset_key')))
                        ->visible(fn (Get $get): bool => self::overrideFields($get('game_id'), $get('preset_key')) !== [])
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();
                    $game = Game::query()->whereKey($data['game_id'])->first();
                    $event = Event::query()->whereKey($data['event_id'])->first();

                    if (! $user instanceof User || $game === null || $event === null) {
                        return;
                    }

                    try {
                        app(StartServerFromPreset::class)->handle(
                            $event,
                            $game,
                            (string) $data['preset_key'],
                            isset($data['title']) ? (string) $data['title'] : null,
                            self::changedOverrides($game, (string) $data['preset_key'], $data['overrides'] ?? []),
                            $user,
                        );
                    } catch (GameServerException $e) {
                        Notification::make()->title(__($e->translationKey))->danger()->send();

                        return;
                    } catch (Throwable) {
                        Notification::make()->title(__('gameservers.start_preset.failed'))->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('gameservers.start_preset.started'))->success()->send();
                }),
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function gamesWithPresets(): array
    {
        return Game::query()->orderBy('name')->get()
            ->filter(fn (Game $game): bool => $game->server_presets !== [])
            ->mapWithKeys(fn (Game $game): array => [$game->id => $game->name])
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public static function presetOptions(mixed $gameId): array
    {
        $game = is_numeric($gameId) ? Game::query()->whereKey((int) $gameId)->first() : null;
        $options = [];

        foreach ($game !== null ? $game->server_presets : [] as $preset) {
            $options[$preset->key] = $preset->name;
        }

        return $options;
    }

    public static function presetLocked(mixed $gameId, string $key): bool
    {
        $game = is_numeric($gameId) ? Game::query()->whereKey((int) $gameId)->first() : null;
        $preset = $game?->findPreset($key);

        return $preset !== null && ! PresetAvailability::isAvailable($preset);
    }

    /** The preset's description, plus why it is locked when it is. */
    public static function presetNote(mixed $gameId, mixed $presetKey): string
    {
        $game = is_numeric($gameId) ? Game::query()->whereKey((int) $gameId)->first() : null;
        $preset = is_string($presetKey) ? $game?->findPreset($presetKey) : null;

        if ($preset === null) {
            return '';
        }

        return trim(($preset->description ?? '').' '.(PresetAvailability::explanation($preset) ?? ''));
    }

    /**
     * One text input per environment key the preset lets the orga override, prefilled
     * with the preset's value.
     *
     * @return list<TextInput>
     */
    public static function overrideFields(mixed $gameId, mixed $presetKey): array
    {
        $game = is_numeric($gameId) ? Game::query()->whereKey((int) $gameId)->first() : null;
        $preset = is_string($presetKey) ? $game?->findPreset($presetKey) : null;

        if ($preset === null) {
            return [];
        }

        return array_map(
            static fn (string $key): TextInput => TextInput::make("overrides.{$key}")
                ->label($key)
                ->default((string) ($preset->config->environment[$key] ?? ''))
                ->maxLength(1000),
            $preset->config->overridable,
        );
    }

    /**
     * Only the values that differ from the preset are sent as overrides.
     *
     * @param  array<string, mixed>  $submitted
     * @return array<string, string>
     */
    public static function changedOverrides(Game $game, string $presetKey, array $submitted): array
    {
        $preset = $game->findPreset($presetKey);
        $changed = [];

        foreach ($submitted as $key => $value) {
            $current = (string) ($preset?->config->environment[(string) $key] ?? '');

            if ((string) $value !== $current) {
                $changed[(string) $key] = (string) $value;
            }
        }

        return $changed;
    }
}
