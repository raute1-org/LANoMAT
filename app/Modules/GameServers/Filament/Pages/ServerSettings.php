<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Pages;

use App\Models\User;
use App\Modules\GameServers\Actions\ChangeServerMap;
use App\Modules\GameServers\Actions\GunGameSettings;
use App\Modules\GameServers\Actions\LinkPelicanServer;
use App\Modules\GameServers\Actions\PowerConfiguredServer;
use App\Modules\GameServers\Actions\UpdateServerSettings;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Url;
use Throwable;
use UnitEnum;

/**
 * Settings of the permanent CS2 servers for orga and admin: general settings
 * (startup variables), power, a live map change and, for a GunGame server, the plugin
 * config. Every operation goes through an Action that authorizes `configure` on the
 * server, so nothing here trusts the Livewire payload: the server is looked up by id
 * on every call and must be a permanent one.
 *
 * Secrets: the password field is never filled from the panel, the environment and file
 * contents never become component state beyond the form fields (the GunGame files hold
 * no secrets, the password lives only in the startup environment).
 */
class ServerSettings extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedAdjustmentsHorizontal;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'server-settings';

    protected string $view = 'filament.pages.server-settings';

    #[Url(as: 'server')]
    public ?int $server = null;

    /** @var array<string, mixed>|null */
    public ?array $settingsData = [];

    /** @var array<string, mixed>|null */
    public ?array $gunGameData = [];

    /** @var array<string, mixed>|null */
    public ?array $mapData = [];

    public bool $loadFailed = false;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User && $user->can('configurePermanent', ServerLink::class);
    }

    public static function getNavigationLabel(): string
    {
        return __('gameservers.settings.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('gameservers.settings.title');
    }

    public function mount(): void
    {
        $this->loadServer();
    }

    public function updatedServer(): void
    {
        $this->loadServer();
    }

    public function settingsForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('settingsData')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    TextInput::make('server_name')
                        ->label(__('gameservers.settings.fields.server_name'))
                        ->required()
                        ->maxLength(64),
                    Select::make('mode')
                        ->label(__('gameservers.settings.fields.mode'))
                        ->options($this->modeOptions())
                        ->required()
                        ->native(false),
                    Select::make('map')
                        ->label(__('gameservers.settings.fields.map'))
                        ->helperText(__('gameservers.settings.fields.map_help'))
                        ->options(array_combine(Cs2Settings::MAPS, Cs2Settings::MAPS))
                        ->searchable()
                        ->native(false),
                    TextInput::make('workshop_id')
                        ->label(__('gameservers.settings.fields.workshop_id'))
                        ->helperText(__('gameservers.settings.fields.workshop_help'))
                        ->regex('/^\d{6,12}$/')
                        ->extraInputAttributes(['class' => 'font-mono', 'inputmode' => 'numeric']),
                    TextInput::make('max_players')
                        ->label(__('gameservers.settings.fields.max_players'))
                        ->numeric()
                        ->integer()
                        ->minValue(1)
                        ->maxValue(64)
                        ->required(),
                    TextInput::make('password')
                        ->label(__('gameservers.settings.fields.password'))
                        ->helperText(__('gameservers.settings.fields.password_help'))
                        ->password()
                        ->autocomplete('new-password')
                        ->maxLength(30)
                        ->alphaDash(),
                    Toggle::make('clear_password')
                        ->label(__('gameservers.settings.fields.clear_password')),
                ]),
            ]);
    }

    public function mapForm(Schema $schema): Schema
    {
        return $schema
            ->statePath('mapData')
            ->components([
                Grid::make(['default' => 1, 'md' => 2])->schema([
                    Select::make('map')
                        ->label(__('gameservers.settings.fields.map'))
                        ->options(array_combine(Cs2Settings::MAPS, Cs2Settings::MAPS))
                        ->searchable()
                        ->native(false),
                    TextInput::make('workshop_id')
                        ->label(__('gameservers.settings.fields.workshop_id'))
                        ->regex('/^\d{6,12}$/')
                        ->extraInputAttributes(['class' => 'font-mono', 'inputmode' => 'numeric']),
                ]),
            ]);
    }

    public function gunGameForm(Schema $schema): Schema
    {
        $fields = [];

        foreach (GunGameConfig::FIELDS as $key => [$kind, $min, $max]) {
            $label = __("gameservers.settings.gungame.fields.{$key}");
            $fields[] = match ($kind) {
                'flag' => Select::make($key)
                    ->label($label)
                    ->options(['on' => __('gameservers.settings.gungame.flag_on'), 'off' => __('gameservers.settings.gungame.flag_off')])
                    ->placeholder(__('gameservers.settings.gungame.unchanged'))
                    ->native(false),
                'int' => TextInput::make($key)
                    ->label($label)
                    ->numeric()
                    ->integer()
                    ->minValue($min)
                    ->maxValue($max),
                default => Select::make($key)
                    ->label($label)
                    ->options(array_combine(GunGameConfig::WEAPONS, GunGameConfig::WEAPONS))
                    ->placeholder(__('gameservers.settings.gungame.unchanged'))
                    ->searchable()
                    ->native(false),
            };
        }

        return $schema
            ->statePath('gunGameData')
            ->components([
                Grid::make(['default' => 1, 'md' => 2, 'xl' => 3])->schema($fields),
                Repeater::make('weapon_order')
                    ->label(__('gameservers.settings.gungame.weapon_order'))
                    ->helperText(__('gameservers.settings.gungame.weapon_order_help'))
                    ->schema([
                        Select::make('weapon')
                            ->label(__('gameservers.settings.gungame.weapon'))
                            ->options(array_combine(GunGameConfig::WEAPONS, GunGameConfig::WEAPONS))
                            ->required()
                            ->searchable()
                            ->native(false),
                        TextInput::make('kills')
                            ->label(__('gameservers.settings.gungame.kills'))
                            ->numeric()
                            ->integer()
                            ->minValue(1)
                            ->maxValue(20),
                    ])
                    ->columns(2)
                    ->reorderable()
                    ->minItems(1)
                    ->maxItems(100)
                    ->addActionLabel(__('gameservers.settings.gungame.add_level')),
            ]);
    }

    public function saveSettings(): void
    {
        $this->guarded(function (User $user, ServerLink $link): void {
            $result = app(UpdateServerSettings::class)->handle($user, $link, $this->settingsInput(), false);
            $this->afterSettingsSaved($result);
        });
    }

    public function saveSettingsAndRestart(): void
    {
        $this->guarded(function (User $user, ServerLink $link): void {
            $result = app(UpdateServerSettings::class)->handle($user, $link, $this->settingsInput(), true);
            $this->afterSettingsSaved($result);
        });
    }

    public function changeMap(): void
    {
        $this->guarded(function (User $user, ServerLink $link): void {
            $state = $this->getSchema('mapForm')?->getState() ?? [];

            app(ChangeServerMap::class)->handle(
                $user,
                $link,
                is_string($state['map'] ?? null) ? $state['map'] : null,
                is_string($state['workshop_id'] ?? null) ? $state['workshop_id'] : null,
            );

            Notification::make()->success()->title(__('gameservers.settings.map.changed'))->send();
        });
    }

    public function power(string $signal): void
    {
        $action = PowerAction::tryFrom($signal);

        if (! in_array($action, [PowerAction::Start, PowerAction::Stop, PowerAction::Restart], true)) {
            return;
        }

        $this->guarded(function (User $user, ServerLink $link) use ($action): void {
            app(PowerConfiguredServer::class)->handle($user, $link, $action);

            Notification::make()->success()->title(__('gameservers.actions.power_sent'))->send();
        });
    }

    public function saveGunGame(): void
    {
        $this->guarded(function (User $user, ServerLink $link): void {
            $state = $this->getSchema('gunGameForm')?->getState() ?? [];
            $reloaded = app(GunGameSettings::class)->save($user, $link, $state);

            Notification::make()
                ->success()
                ->title(__($reloaded ? 'gameservers.settings.gungame.saved' : 'gameservers.settings.gungame.saved_restart_needed'))
                ->send();
        });
    }

    /** Admin only: adopt this external server into the panel's management. */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('link')
                ->label(__('gameservers.settings.link.action'))
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => $this->server !== null && ! ($this->selectedLink()?->hasPelicanLink() ?? true) && (auth()->user()?->can('managePermanent', ServerLink::class) ?? false))
                ->authorize(fn (): bool => auth()->user()?->can('managePermanent', ServerLink::class) ?? false)
                ->modalDescription(__('gameservers.settings.link.help'))
                ->schema([
                    TextInput::make('reference')
                        ->label(__('gameservers.settings.link.reference'))
                        ->required()
                        ->maxLength(64)
                        ->extraInputAttributes(['class' => 'font-mono']),
                    Toggle::make('gungame')
                        ->label(__('gameservers.settings.link.gungame')),
                ])
                ->action(function (array $data): void {
                    // Re-check at execution time: the visible()/authorize() above are UI only.
                    Gate::authorize('managePermanent', ServerLink::class);
                    $link = $this->selectedLink();

                    if ($link === null) {
                        return;
                    }

                    try {
                        app(LinkPelicanServer::class)->handle($link, (string) $data['reference'], (bool) ($data['gungame'] ?? false));
                    } catch (GameServerException $e) {
                        Notification::make()->danger()->title(__($e->translationKey))->send();

                        return;
                    }

                    Notification::make()->success()->title(__('gameservers.settings.link.done'))->send();
                    $this->loadServer();
                }),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function getViewData(): array
    {
        $link = $this->selectedLink();

        return [
            'servers' => ServerLink::query()->permanent()->orderBy('title')->get(['id', 'title', 'pelican_server_id', 'pelican_server_identifier']),
            'link' => $link,
            'linked' => $link?->hasPelicanLink() ?? false,
            'isGunGame' => $link?->preset_key === GunGameConfig::PRESET_KEY,
            'state' => $this->runtimeState($link),
            'canPower' => $link !== null && (auth()->user()?->can('configure', $link) ?? false),
        ];
    }

    private function selectedLink(): ?ServerLink
    {
        return $this->server === null ? null : ServerLink::query()->permanent()->find($this->server);
    }

    /** The panel's live state, or null when it cannot be read (the page never fails over it). */
    private function runtimeState(?ServerLink $link): ?ServerState
    {
        if ($link === null || ! $link->hasPelicanLink()) {
            return null;
        }

        try {
            return app(PelicanClient::class)->getResources((string) $link->pelican_server_identifier)->state;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Loads the selected server's current values into the forms. Reading failures
     * flag the page instead of throwing; the password is never loaded.
     */
    private function loadServer(): void
    {
        $this->loadFailed = false;
        $this->settingsData = [];
        $this->gunGameData = [];
        $this->mapData = [];
        $link = $this->selectedLink();
        $user = auth()->user();

        if ($link !== null && $user instanceof User && $link->hasPelicanLink() && $user->can('configure', $link)) {
            try {
                $server = app(PelicanClient::class)->getServer((string) $link->pelican_server_id);
                $container = is_array($server->meta['container'] ?? null) ? $server->meta['container'] : [];
                $environment = is_array($container['environment'] ?? null) ? $container['environment'] : [];
                $this->settingsData = Cs2Settings::formState($environment) + ['password' => null, 'clear_password' => false];

                if ($link->preset_key === GunGameConfig::PRESET_KEY) {
                    $this->gunGameData = app(GunGameSettings::class)->load($user, $link);
                }
            } catch (Throwable) {
                $this->loadFailed = true;
            }
        }

        $this->getSchema('settingsForm')?->fill($this->settingsData);
        $this->getSchema('gunGameForm')?->fill($this->gunGameData);
        $this->getSchema('mapForm')?->fill([]);
    }

    /**
     * @return array<string, mixed>
     */
    private function settingsInput(): array
    {
        /** @var array<string, mixed> $state */
        $state = $this->getSchema('settingsForm')?->getState() ?? [];

        return $state;
    }

    /**
     * @param  array{skipped: list<string>, restarted: bool}  $result
     */
    private function afterSettingsSaved(array $result): void
    {
        // Never keep a typed password in the component state.
        $this->settingsData = array_merge($this->settingsData ?? [], ['password' => null, 'clear_password' => false]);
        $this->getSchema('settingsForm')?->fill($this->settingsData);

        Notification::make()
            ->success()
            ->title(__($result['restarted'] ? 'gameservers.settings.general.saved_restarted' : 'gameservers.settings.general.saved'))
            ->send();

        if ($result['skipped'] !== []) {
            Notification::make()->warning()->title(__('gameservers.settings.general.skipped', ['variables' => implode(', ', $result['skipped'])]))->send();
        }
    }

    /**
     * Runs one operation on the selected permanent server and turns its failures into
     * notifications. The Actions authorize; this only resolves the actor and the record.
     *
     * @param  callable(User, ServerLink): void  $operation
     */
    private function guarded(callable $operation): void
    {
        $user = auth()->user();
        $link = $this->selectedLink();

        if (! $user instanceof User || $link === null) {
            return;
        }

        try {
            $operation($user, $link);
        } catch (ValidationException $e) {
            Notification::make()->danger()->title((string) collect($e->errors())->flatten()->first())->send();
        } catch (GameServerException $e) {
            Notification::make()->danger()->title(__($e->translationKey))->send();
        }
    }

    /**
     * @return array<string, string>
     */
    private function modeOptions(): array
    {
        $options = [];

        foreach (array_keys(Cs2Settings::MODES) as $mode) {
            $options[$mode] = __("gameservers.settings.modes.{$mode}");
        }

        return $options;
    }
}
