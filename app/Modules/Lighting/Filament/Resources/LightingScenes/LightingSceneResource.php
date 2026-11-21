<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingScenes;

use App\Models\User;
use App\Modules\Lighting\Actions\PlayScene;
use App\Modules\Lighting\Exceptions\LightingRateLimited;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\CreateLightingScene;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\EditLightingScene;
use App\Modules\Lighting\Filament\Resources\LightingScenes\Pages\ListLightingScenes;
use App\Modules\Lighting\Models\LightingScene;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\ColorPicker;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use UnitEnum;

class LightingSceneResource extends Resource
{
    protected static ?string $model = LightingScene::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSwatch;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 72;

    public static function getModelLabel(): string
    {
        return __('lighting.scene.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lighting.scene.plural');
    }

    public static function form(Schema $schema): Schema
    {
        $unit = fn (string $name, string $label) => TextInput::make($name)
            ->label($label)->numeric()->minValue(0)->maxValue(1)->step(0.05);

        return $schema->components([
            TextInput::make('name')->label(__('lighting.scene.name'))->required()->maxLength(80),
            Textarea::make('description')->label(__('lighting.scene.description'))->rows(2)->maxLength(300),
            Toggle::make('blackout')->label(__('lighting.scene.blackout'))->helperText(__('lighting.scene.blackout_hint')),
            Section::make(__('lighting.scene.all_lights'))
                ->description(__('lighting.scene.all_lights_hint'))
                ->columns(2)
                ->schema([
                    ColorPicker::make('states.all.hex')->label(__('lighting.color'))->regex('/^#[0-9a-fA-F]{6}$/'),
                    $unit('states.all.brightness', __('lighting.brightness')),
                    $unit('states.all.temperature', __('lighting.temperature')),
                    $unit('states.all.white_level', __('lighting.white_level')),
                ]),
            Section::make(__('lighting.scene.per_light'))
                ->description(__('lighting.scene.per_light_hint'))
                ->schema([
                    Repeater::make('fixture_states')
                        ->label(__('lighting.scene.per_light'))
                        ->columns(3)
                        ->defaultItems(0)
                        ->maxItems(64)
                        ->addActionLabel(__('lighting.scene.add_light'))
                        ->schema([
                            TextInput::make('id')->label(__('lighting.scene.light_id'))->numeric()->integer()->minValue(1)->maxValue(64)->required(),
                            ColorPicker::make('hex')->label(__('lighting.color'))->regex('/^#[0-9a-fA-F]{6}$/'),
                            $unit('brightness', __('lighting.brightness')),
                        ]),
                ]),
            Section::make(__('lighting.scene.pattern'))
                ->description(__('lighting.scene.pattern_hint'))
                ->columns(3)
                ->schema([
                    ColorPicker::make('pattern.a')->label(__('lighting.pattern_a'))->regex('/^#[0-9a-fA-F]{6}$/'),
                    ColorPicker::make('pattern.b')->label(__('lighting.pattern_b'))->regex('/^#[0-9a-fA-F]{6}$/'),
                    $unit('pattern.brightness', __('lighting.brightness')),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('lighting.scene.name'))->searchable()->sortable(),
                TextColumn::make('description')->label(__('lighting.scene.description'))->limit(60)->toggleable(),
                IconColumn::make('blackout')->label(__('lighting.scene.blackout'))->boolean(),
            ])
            ->defaultSort('name')
            ->recordActions([
                Action::make('play')
                    ->label(__('lighting.scene.play'))
                    ->icon(Heroicon::OutlinedPlay)
                    ->visible(fn (): bool => auth()->user()?->can('lighting.control') ?? false)
                    ->action(function (LightingScene $record): void {
                        $user = auth()->user();
                        abort_unless($user instanceof User, 403);

                        try {
                            $result = app(PlayScene::class)($user, $record);
                        } catch (LightingRateLimited) {
                            Notification::make()->danger()->title(__('lighting.errors.rate_limited'))->send();

                            return;
                        }

                        $result->ok
                            ? Notification::make()->success()->title(__('lighting.scene.played'))->send()
                            : Notification::make()->danger()->title($result->reachable ? __('lighting.errors.rejected') : __('lighting.errors.unreachable'))->send();
                    }),
                EditAction::make(),
                DeleteAction::make(),
            ])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLightingScenes::route('/'),
            'create' => CreateLightingScene::route('/create'),
            'edit' => EditLightingScene::route('/{record}/edit'),
        ];
    }

    /**
     * Drops empty form values so the stored JSON only holds real settings.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function prune(array $data): array
    {
        $perLight = $data['fixture_states'] ?? [];
        unset($data['fixture_states']);

        foreach (['states', 'pattern'] as $key) {
            $value = $data[$key] ?? null;

            if (! is_array($value)) {
                $data[$key] = null;

                continue;
            }

            $clean = self::pruneRecursive($value);
            $data[$key] = $clean === [] ? null : $clean;
        }

        foreach (is_array($perLight) ? $perLight : [] as $row) {
            if (! is_array($row) || ! isset($row['id']) || ! is_numeric($row['id'])) {
                continue;
            }

            $id = (int) $row['id'];
            $state = self::pruneRecursive(array_diff_key($row, ['id' => true]));

            if ($id >= 1 && $id <= 64 && $state !== []) {
                $data['states'] = [...($data['states'] ?? []), (string) $id => $state];
            }
        }

        if (is_array($data['pattern'] ?? null) && (! isset($data['pattern']['a']) || ! isset($data['pattern']['b']))) {
            $data['pattern'] = null;
        }

        return $data;
    }

    /**
     * Splits the stored states into the form shape: `all` stays, per-light ids
     * become repeater rows.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    public static function explode(array $data): array
    {
        $states = is_array($data['states'] ?? null) ? $data['states'] : [];
        $rows = [];

        foreach ($states as $key => $state) {
            if ($key !== 'all' && is_numeric($key) && is_array($state)) {
                $rows[] = ['id' => (int) $key, ...$state];
                unset($states[$key]);
            }
        }

        $data['states'] = $states;
        $data['fixture_states'] = $rows;

        return $data;
    }

    /**
     * @param  array<array-key, mixed>  $value
     * @return array<array-key, mixed>
     */
    private static function pruneRecursive(array $value): array
    {
        $out = [];

        foreach ($value as $k => $v) {
            if (is_array($v)) {
                $v = self::pruneRecursive($v);
                if ($v === []) {
                    continue;
                }
            } elseif ($v === null || $v === '') {
                continue;
            }
            $out[$k] = $v;
        }

        return $out;
    }
}
