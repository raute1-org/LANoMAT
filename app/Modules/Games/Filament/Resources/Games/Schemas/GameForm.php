<?php

namespace App\Modules\Games\Filament\Resources\Games\Schemas;

use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;

class GameForm
{
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
                    ->maxLength(255),

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
                    ->dehydrated(false),

                // Typed default_server_config fields. `default_server_config`
                // itself is not fillable (see Game::$fillable), so these flat
                // keys are marshalled into a ServerConfig by the Create/Edit
                // pages, mirroring Infoscreen's config/SceneConfig handling.
                TextInput::make('max_players')
                    ->label(__('games.fields.max_players'))
                    ->numeric()
                    ->integer()
                    ->minValue(1)
                    ->visible(fn (Get $get): bool => $get('default_config_mode') !== 'upload'),
                TextInput::make('map')
                    ->label(__('games.fields.map'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('default_config_mode') !== 'upload'),
                TextInput::make('password')
                    ->label(__('games.fields.password'))
                    ->maxLength(255)
                    ->visible(fn (Get $get): bool => $get('default_config_mode') !== 'upload'),

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
                    ->visible(fn (Get $get): bool => $get('default_config_mode') === 'upload'),

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
                    ->schema([
                        TextInput::make('key')
                            ->label(__('games.fields.preset_key'))
                            ->required()
                            ->maxLength(255),
                        TextInput::make('name')
                            ->label(__('games.fields.preset_name'))
                            ->required()
                            ->maxLength(255),
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
                    ])
                    ->columns(3)
                    ->itemLabel(fn (array $state): ?string => $state['name'] ?? null)
                    ->addActionLabel(__('games.fields.preset_add'))
                    ->reorderable(false)
                    ->defaultItems(0),

                // Typed install_hint fields ("So kommst du ran", roadmap
                // 7.5): install_hint itself is not fillable (see Game
                // model), so these flat keys are marshalled into an
                // InstallHint by the Create/Edit pages, mirroring the
                // default_server_config fields above.
                TextInput::make('install_hint_steam_url')
                    ->label(__('games.fields.install_hint_steam_url'))
                    ->maxLength(255)
                    ->url(),
                TextInput::make('install_hint_share_url')
                    ->label(__('games.fields.install_hint_share_url'))
                    ->maxLength(255)
                    ->url(),
                TextInput::make('install_hint_version_note')
                    ->label(__('games.fields.install_hint_version_note'))
                    ->maxLength(255),
            ]);
    }
}
