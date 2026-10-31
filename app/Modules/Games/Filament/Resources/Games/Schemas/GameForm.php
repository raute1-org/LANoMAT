<?php

namespace App\Modules\Games\Filament\Resources\Games\Schemas;

use App\Modules\Games\Contracts\EggCatalog;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Fieldset;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class GameForm
{
    /**
     * The Pelican egg, default server config, presets and the "So kommst du
     * ran" install hint only matter to game servers: hidden (and left
     * untouched on save, see CreateGame/EditGame) while that optional module
     * is off.
     */
    public static function gameServersEnabled(): bool
    {
        return app(Modules::class)->enabled(Module::GameServers);
    }

    /**
     * The panel's eggs for the preset egg select, or null when the catalog is
     * not available (the form then shows a plain uuid text input).
     *
     * @return array<string, string>|null
     */
    private static function eggOptions(): ?array
    {
        return app(EggCatalog::class)->options();
    }

    /**
     * One server preset: egg reference, limits, environment (with a per-key
     * "orga may override" flag), extra ports, readiness check, connect template
     * and content bundle. Secrets (Steam Web API key, GSLT) are deliberately not
     * fields: they come from the environment config and are only declared here as
     * a requirement.
     *
     * @return list<Component>
     */
    private static function presetSchema(): array
    {
        $maxRam = fn (): int => (int) config('services.pelican.max_ram_mb');

        return [
            TextInput::make('key')
                ->label(__('games.fields.preset_key'))
                ->helperText(__('games.fields.preset_key_help'))
                ->required()
                ->alphaDash()
                ->maxLength(255),
            TextInput::make('name')
                ->label(__('games.fields.preset_name'))
                ->required()
                ->maxLength(255),
            Textarea::make('description')
                ->label(__('games.fields.preset_description'))
                ->helperText(__('games.fields.preset_description_help'))
                ->rows(2)
                ->maxLength(1000)
                ->columnSpanFull(),
            Toggle::make('enabled')
                ->label(__('games.fields.preset_enabled'))
                ->helperText(__('games.fields.preset_enabled_help'))
                ->default(true),
            CheckboxList::make('requires')
                ->label(__('games.fields.preset_requires'))
                ->helperText(__('games.fields.preset_requires_help'))
                ->options([
                    'authkey' => __('games.fields.requires_authkey'),
                    'gslt' => __('games.fields.requires_gslt'),
                    'content' => __('games.fields.requires_content'),
                ]),

            Fieldset::make(__('games.fields.group_egg'))
                ->schema([
                    Select::make('egg_uuid')
                        ->label(__('games.fields.egg_uuid'))
                        ->helperText(__('games.fields.egg_uuid_select_help'))
                        ->options(fn (): array => self::eggOptions() ?? [])
                        ->searchable()
                        ->visible(fn (): bool => self::eggOptions() !== null),
                    TextInput::make('egg_uuid_manual')
                        ->label(__('games.fields.egg_uuid'))
                        ->helperText(__('games.fields.egg_uuid_text_help'))
                        ->maxLength(64)
                        ->visible(fn (): bool => self::eggOptions() === null),
                    TextInput::make('egg_name')
                        ->label(__('games.fields.egg_name'))
                        ->helperText(__('games.fields.egg_name_help'))
                        ->maxLength(255),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Fieldset::make(__('games.fields.group_resources'))
                ->schema([
                    TextInput::make('max_players')
                        ->label(__('games.fields.max_players'))
                        ->numeric()
                        ->integer()
                        ->minValue(1),
                    TextInput::make('map')
                        ->label(__('games.fields.map'))
                        ->maxLength(255),
                    TextInput::make('password')
                        ->label(__('games.fields.password'))
                        ->maxLength(255),
                    TextInput::make('memory_mb')
                        ->label(__('games.fields.memory_mb'))
                        ->helperText(fn (): string => __('games.fields.memory_mb_help', ['max' => $maxRam()]))
                        ->numeric()
                        ->integer()
                        ->minValue(128)
                        ->suffix('MB')
                        ->rules([
                            fn (): Closure => function (string $attribute, mixed $value, Closure $fail) use ($maxRam): void {
                                if (is_numeric($value) && (int) $value > $maxRam()) {
                                    $fail(__('gameservers.errors.ram_cap_exceeded').' ('.__('games.fields.memory_mb_max', ['max' => $maxRam()]).')');
                                }
                            },
                        ]),
                    TextInput::make('disk_mb')
                        ->label(__('games.fields.disk_mb'))
                        ->helperText(__('games.fields.disk_mb_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(256)
                        ->suffix('MB'),
                    TextInput::make('cpu_percent')
                        ->label(__('games.fields.cpu_percent'))
                        ->helperText(__('games.fields.cpu_percent_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(0)
                        ->suffix('%'),
                ])
                ->columns(3)
                ->columnSpanFull(),

            Repeater::make('environment')
                ->label(__('games.fields.environment'))
                ->helperText(__('games.fields.environment_help'))
                ->schema([
                    TextInput::make('key')
                        ->label(__('games.fields.env_key'))
                        ->required()
                        ->regex('/^[A-Z][A-Z0-9_]*$/')
                        ->validationMessages(['regex' => __('games.fields.env_key_invalid')])
                        ->maxLength(255),
                    TextInput::make('value')
                        ->label(__('games.fields.env_value'))
                        ->maxLength(1000),
                    Toggle::make('overridable')
                        ->label(__('games.fields.env_overridable'))
                        ->helperText(__('games.fields.env_overridable_help'))
                        ->inline(false),
                ])
                ->columns(3)
                ->addActionLabel(__('games.fields.env_add'))
                ->reorderable(false)
                ->defaultItems(0)
                ->columnSpanFull(),

            Fieldset::make(__('games.fields.group_network'))
                ->schema([
                    TagsInput::make('extra_ports')
                        ->label(__('games.fields.extra_ports'))
                        ->helperText(__('games.fields.extra_ports_help'))
                        ->placeholder('TV_PORT'),
                    Select::make('readiness_type')
                        ->label(__('games.fields.readiness_type'))
                        ->helperText(__('games.fields.readiness_type_help'))
                        ->options([
                            'none' => __('games.fields.readiness_none'),
                            'a2s' => __('games.fields.readiness_a2s'),
                            'minecraft' => __('games.fields.readiness_minecraft'),
                        ])
                        ->default('none')
                        ->live()
                        ->selectablePlaceholder(false),
                    TextInput::make('readiness_game')
                        ->label(__('games.fields.readiness_game'))
                        ->helperText(__('games.fields.readiness_game_help'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('readiness_type') === 'a2s'),
                    TextInput::make('readiness_folder')
                        ->label(__('games.fields.readiness_folder'))
                        ->helperText(__('games.fields.readiness_folder_help'))
                        ->maxLength(255)
                        ->visible(fn (Get $get): bool => $get('readiness_type') === 'a2s'),
                    TextInput::make('readiness_timeout')
                        ->label(__('games.fields.readiness_timeout'))
                        ->helperText(__('games.fields.readiness_timeout_help'))
                        ->numeric()
                        ->integer()
                        ->minValue(30)
                        ->maxValue(1800)
                        ->suffix('s')
                        ->visible(fn (Get $get): bool => $get('readiness_type') === 'a2s'),
                    TextInput::make('connect_template')
                        ->label(__('games.fields.connect_template'))
                        ->helperText(__('games.fields.connect_template_help'))
                        ->maxLength(255)
                        ->placeholder('steam://connect/{ip}:{port}')
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),

            Fieldset::make(__('games.fields.group_content'))
                ->schema([
                    Select::make('content_type')
                        ->label(__('games.fields.content_type'))
                        ->options([
                            'none' => __('games.fields.content_none'),
                            'workshop' => __('games.fields.content_workshop'),
                            'upload' => __('games.fields.content_upload'),
                        ])
                        ->default('none')
                        ->live()
                        ->selectablePlaceholder(false),
                    TextInput::make('content_ref')
                        ->label(fn (Get $get): string => $get('content_type') === 'upload' ? __('games.fields.content_ref_upload') : __('games.fields.content_ref_workshop'))
                        ->helperText(__('games.fields.content_ref_help'))
                        ->maxLength(500)
                        ->visible(fn (Get $get): bool => in_array($get('content_type'), ['workshop', 'upload'], true)),
                    TextInput::make('install_marker')
                        ->label(__('games.fields.install_marker'))
                        ->helperText(__('games.fields.install_marker_help'))
                        ->maxLength(255)
                        ->columnSpanFull(),
                ])
                ->columns(2)
                ->columnSpanFull(),
        ];
    }

    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('name')
                    ->label(__('games.fields.name'))
                    ->required()
                    ->maxLength(255),
                TextInput::make('slug')
                    ->label(__('games.fields.slug'))
                    ->required()
                    ->unique(ignoreRecord: true)
                    ->maxLength(255),
                FileUpload::make('icon_path')
                    ->label(__('games.fields.icon'))
                    ->disk('public')
                    ->directory('game-icons')
                    ->image(),
                TextInput::make('min_team_size')
                    ->label(__('games.fields.min_team_size'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('max_team_size')
                    ->label(__('games.fields.max_team_size'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(1),
                TextInput::make('pelican_egg_id')
                    ->label(__('games.fields.pelican_egg_id'))
                    ->maxLength(255)
                    ->visible(self::gameServersEnabled(...)),

                // Mode toggle for the game's default config (T9,
                // EffectiveConfig::resolve's "neither preset nor upload"
                // fallback): a Nitrado/ShockByte-style settings form, or an
                // uploaded raw config file — never both (roadmap 6.6: "genau
                // eine Config auf dem Server ausgeführt — eine Wahrheit").
                // Purely a Filament UX affordance; the two branches below are
                // mutually exclusive in the UI so only one ever gets filled
                // in, and CreateGame/EditGame persist only the active one.
                Radio::make('default_config_mode')
                    ->label(__('games.fields.default_config_mode'))
                    ->options([
                        'form' => __('games.fields.default_config_mode_form'),
                        'upload' => __('games.fields.default_config_mode_upload'),
                    ])
                    ->default('form')
                    ->live()
                    ->inline()
                    ->dehydrated(false)
                    ->visible(self::gameServersEnabled(...)),

                // Typed default_server_config fields. `default_server_config`
                // itself is not fillable (see Game::$fillable), so these flat
                // keys are marshalled into a ServerConfig by the Create/Edit
                // pages, mirroring Infoscreen's config/SceneConfig handling.
                TextInput::make('max_players')
                    ->label(__('games.fields.max_players'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (Get $get): bool => self::gameServersEnabled() && $get('default_config_mode') !== 'upload'),
                TextInput::make('map')
                    ->label(__('games.fields.map'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::gameServersEnabled() && $get('default_config_mode') !== 'upload'),
                TextInput::make('password')
                    ->label(__('games.fields.password'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => self::gameServersEnabled() && $get('default_config_mode') !== 'upload'),

                // Upload mode: an orga uploads a raw config file instead of
                // filling in the settings form above. Stored via
                // UploadServerConfig to the `public` disk (never Base64 —
                // see CLAUDE.md's uploads rule); this field only ever holds
                // the resulting path, which the Create/Edit pages feed to
                // EffectiveConfig to resolve the actual ServerConfig.
                FileUpload::make('default_config_upload')
                    ->label(__('games.fields.default_config_upload'))
                    ->disk('public')
                    ->directory('gameserver-configs')
                    ->visible(fn (Get $get): bool => self::gameServersEnabled() && $get('default_config_mode') === 'upload'),

                // Per-game one-click presets (T9): a Nitrado/ShockByte-style
                // settings-form repeater, each row a named, reusable
                // ServerConfig an orga can later pick by key when
                // provisioning a match server (EffectiveConfig::resolve's
                // $presetKey). `server_presets` itself is not fillable (see
                // Game model), so this is marshalled by the Create/Edit
                // pages, mirroring the menu/MenuCast Repeater in
                // FoodOrderForm.
                Repeater::make('server_presets')
                    ->label(__('games.fields.server_presets'))
                    ->helperText(__('games.fields.server_presets_help'))
                    ->schema(self::presetSchema())
                    ->columns(2)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel(__('games.fields.preset_add'))
                    ->collapsible()
                    ->reorderable(false)
                    ->defaultItems(0)
                    ->columnSpanFull()
                    ->visible(self::gameServersEnabled(...)),

                // Typed install_hint fields ("So kommst du ran", roadmap
                // 7.5): install_hint itself is not fillable (see Game
                // model), so these flat keys are marshalled into an
                // InstallHint by the Create/Edit pages, mirroring the
                // default_server_config fields above.
                TextInput::make('install_hint_steam_url')
                    ->label(__('games.fields.install_hint_steam_url'))
                    ->maxLength(255)
                    ->url()
                    ->visible(self::gameServersEnabled(...)),
                TextInput::make('install_hint_share_url')
                    ->label(__('games.fields.install_hint_share_url'))
                    ->maxLength(255)
                    ->url()
                    ->visible(self::gameServersEnabled(...)),
                TextInput::make('install_hint_version_note')
                    ->label(__('games.fields.install_hint_version_note'))
                    ->maxLength(255)
                    ->visible(self::gameServersEnabled(...)),

                // Typed spectate_hint fields ("So schaust du zu", M10 T8):
                // spectate_hint itself is not fillable (see Game model), so
                // these flat keys are marshalled into a SpectateHint by the
                // Create/Edit pages, mirroring the install_hint fields above.
                TextInput::make('spectate_hint_gotv_connect')
                    ->label(__('games.fields.spectate_hint_gotv_connect'))
                    ->maxLength(255),
                TextInput::make('spectate_hint_observer_note')
                    ->label(__('games.fields.spectate_hint_observer_note'))
                    ->maxLength(255),
                TextInput::make('spectate_hint_replay_note')
                    ->label(__('games.fields.spectate_hint_replay_note'))
                    ->maxLength(255),
            ]);
    }
}
