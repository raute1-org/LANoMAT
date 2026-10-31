<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\PermanentServers\Pages;

use App\Models\User;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Games\Models\Game;
use App\Modules\GameServers\Actions\CreatePermanentServer;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\QueryProtocol;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Filament\Resources\PermanentServers\PermanentServerResource;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\ListServerLinks;
use App\Modules\GameServers\Models\ServerLink;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Filament\Schemas\Components\Callout;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * "Dauerserver anlegen": one form for the three sources. Pelican (preset on a
 * chosen node), Docker (a Custom Servers container on a managed host) and
 * external (just registered); the fields change with the source.
 */
class ListPermanentServers extends ListRecords
{
    protected static string $resource = PermanentServerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('create')
                ->label(__('gameservers.permanent.actions.create'))
                ->icon('heroicon-o-plus')
                ->authorize(fn (): bool => Auth::user()?->can('managePermanent', ServerLink::class) ?? false)
                ->modalHeading(__('gameservers.permanent.actions.create'))
                ->modalSubmitActionLabel(__('gameservers.permanent.actions.create'))
                ->schema([
                    Select::make('source')
                        ->label(__('gameservers.permanent.fields.source'))
                        ->options(self::sourceOptions())
                        ->default(ServerSource::Pelican->value)
                        ->live()
                        ->required()
                        ->native(false),
                    Select::make('game_id')
                        ->label(__('gameservers.permanent.fields.game'))
                        ->options(fn (Get $get): array => $get('source') === ServerSource::Pelican->value
                            ? ListServerLinks::gamesWithPresets()
                            : Game::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->live()
                        ->afterStateUpdated(fn (callable $set) => $set('preset_key', null))
                        ->searchable()
                        ->required(),
                    TextInput::make('title')
                        ->label(__('gameservers.permanent.fields.title'))
                        ->required()
                        ->maxLength(64),
                    Textarea::make('description')
                        ->label(__('gameservers.permanent.fields.description'))
                        ->helperText(__('gameservers.permanent.fields.description_help'))
                        ->rows(3)
                        ->maxLength(1000),

                    // Pelican: preset, node, overrides.
                    Fieldset::make(ServerSource::Pelican->label())
                        ->visible(fn (Get $get): bool => $get('source') === ServerSource::Pelican->value)
                        ->schema([
                            Select::make('preset_key')
                                ->label(__('gameservers.permanent.fields.preset'))
                                ->options(fn (Get $get): array => ListServerLinks::presetOptions($get('game_id')))
                                ->disableOptionWhen(fn (string $value, Get $get): bool => ListServerLinks::presetLocked($get('game_id'), $value))
                                ->live()
                                ->required(fn (Get $get): bool => $get('source') === ServerSource::Pelican->value),
                            Callout::make(fn (Get $get): string => ListServerLinks::presetNote($get('game_id'), $get('preset_key')))
                                ->visible(fn (Get $get): bool => ListServerLinks::presetNote($get('game_id'), $get('preset_key')) !== ''),
                            Select::make('node_id')
                                ->label(__('gameservers.permanent.fields.node'))
                                ->helperText(__('gameservers.permanent.fields.node_help'))
                                ->options(fn (): array => self::nodeOptions())
                                ->disableOptionWhen(fn (string $value): bool => str_ends_with($value, '!'))
                                ->native(false),
                            Fieldset::make(__('gameservers.permanent.fields.overrides'))
                                ->schema(fn (Get $get): array => ListServerLinks::overrideFields($get('game_id'), $get('preset_key')))
                                ->visible(fn (Get $get): bool => ListServerLinks::overrideFields($get('game_id'), $get('preset_key')) !== [])
                                ->columns(2),
                        ]),

                    // Docker: the container definition.
                    Select::make('custom_server_id')
                        ->label(__('gameservers.permanent.fields.custom_server'))
                        ->helperText(__('gameservers.permanent.fields.custom_server_help'))
                        ->options(fn (): array => CustomServer::query()->orderBy('name')->pluck('name', 'id')->all())
                        ->visible(fn (Get $get): bool => $get('source') === ServerSource::Docker->value)
                        ->required(fn (Get $get): bool => $get('source') === ServerSource::Docker->value)
                        ->searchable(),

                    // Docker and external: where players join and how to ask for status.
                    Fieldset::make(__('gameservers.permanent.fields.address'))
                        ->visible(fn (Get $get): bool => $get('source') !== ServerSource::Pelican->value)
                        ->schema([
                            TextInput::make('address')
                                ->label(__('gameservers.permanent.fields.address'))
                                ->required(fn (Get $get): bool => $get('source') !== ServerSource::Pelican->value)
                                ->maxLength(255)
                                ->extraInputAttributes(['class' => 'font-mono']),
                            TextInput::make('port')
                                ->label(__('gameservers.permanent.fields.port'))
                                ->required(fn (Get $get): bool => $get('source') !== ServerSource::Pelican->value)
                                ->numeric()
                                ->integer()
                                ->minValue(1)
                                ->maxValue(65535)
                                ->extraInputAttributes(['class' => 'font-mono']),
                            Select::make('query_protocol')
                                ->label(__('gameservers.permanent.fields.query_protocol'))
                                ->options(collect(QueryProtocol::cases())->mapWithKeys(
                                    fn (QueryProtocol $protocol): array => [$protocol->value => $protocol->label()],
                                ))
                                ->placeholder(__('gameservers.permanent.fields.query_protocol_none'))
                                ->native(false),
                            TextInput::make('connect_string')
                                ->label(__('gameservers.permanent.fields.connect_string'))
                                ->maxLength(255)
                                ->extraInputAttributes(['class' => 'font-mono']),
                        ])
                        ->columns(2),
                ])
                ->action(function (array $data): void {
                    $user = Auth::user();
                    $game = Game::query()->whereKey($data['game_id'] ?? null)->first();

                    if (! $user instanceof User || $game === null) {
                        return;
                    }

                    try {
                        self::create($user, $game, $data);
                    } catch (GameServerException $e) {
                        Notification::make()->title(__($e->translationKey))->danger()->send();

                        return;
                    } catch (Throwable) {
                        Notification::make()->title(__('gameservers.permanent.actions.create_failed'))->danger()->send();

                        return;
                    }

                    Notification::make()->title(__('gameservers.permanent.actions.created'))->success()->send();
                }),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws GameServerException
     */
    private static function create(User $user, Game $game, array $data): void
    {
        $action = app(CreatePermanentServer::class);
        $title = (string) ($data['title'] ?? '');
        $description = isset($data['description']) ? (string) $data['description'] : null;
        $protocol = filled($data['query_protocol'] ?? null) ? QueryProtocol::from((string) $data['query_protocol']) : null;
        $connect = isset($data['connect_string']) ? (string) $data['connect_string'] : null;
        $address = (string) ($data['address'] ?? '');
        $port = (int) ($data['port'] ?? 0);

        match (ServerSource::from((string) ($data['source'] ?? ''))) {
            ServerSource::Pelican => $action->pelican(
                $user,
                $game,
                (string) ($data['preset_key'] ?? ''),
                $title,
                $description,
                filled($data['node_id'] ?? null) ? rtrim((string) $data['node_id'], '!') : null,
                ListServerLinks::changedOverrides($game, (string) ($data['preset_key'] ?? ''), $data['overrides'] ?? []),
            ),
            ServerSource::Docker => $action->docker(
                $user,
                $game,
                CustomServer::query()->whereKey($data['custom_server_id'] ?? null)->first()
                    ?? throw GameServerException::permanentInputInvalid('gameservers.permanent.errors.docker_required'),
                $title,
                $description,
                $address,
                $port,
                $protocol,
                $connect,
            ),
            ServerSource::External => $action->external($user, $game, $title, $description, $address, $port, $protocol, $connect),
        };
    }

    /**
     * @return array<string, string>
     */
    private static function sourceOptions(): array
    {
        $options = [];

        foreach (ServerSource::cases() as $source) {
            if ($source === ServerSource::Docker && ! app(Modules::class)->enabled(Module::CustomServers)) {
                continue;
            }

            $options[$source->value] = $source->label();
        }

        return $options;
    }

    /**
     * The panel's nodes; a node in maintenance is listed but disabled (its key ends in `!`).
     * An unreachable panel gives an empty list, and the default node is used.
     *
     * @return array<string, string>
     */
    private static function nodeOptions(): array
    {
        try {
            $nodes = app(PelicanClient::class)->listNodes();
        } catch (Throwable) {
            return [];
        }

        $options = [];

        foreach ($nodes as $node) {
            $options[$node->id.($node->maintenanceMode ? '!' : '')] = "{$node->name} ({$node->fqdn})";
        }

        return $options;
    }
}
