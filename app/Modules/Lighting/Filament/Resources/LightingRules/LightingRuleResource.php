<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Resources\LightingRules;

use App\Modules\Lighting\Enums\LightingTrigger;
use App\Modules\Lighting\Filament\Resources\LightingRules\Pages\CreateLightingRule;
use App\Modules\Lighting\Filament\Resources\LightingRules\Pages\EditLightingRule;
use App\Modules\Lighting\Filament\Resources\LightingRules\Pages\ListLightingRules;
use App\Modules\Lighting\Models\LightingRule;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;
use UnitEnum;

class LightingRuleResource extends Resource
{
    protected static ?string $model = LightingRule::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBolt;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 73;

    public static function getModelLabel(): string
    {
        return __('lighting.rule.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('lighting.rule.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('trigger')->label(__('lighting.rule.trigger'))->options(LightingTrigger::options())->required(),
            Select::make('lighting_scene_id')->label(__('lighting.rule.scene'))->relationship('scene', 'name')->required()->preload(),
            Toggle::make('active')->label(__('lighting.rule.active'))->helperText(__('lighting.rule.active_hint'))->default(false),
            TextInput::make('cooldown_seconds')->label(__('lighting.rule.cooldown'))->numeric()->integer()->minValue(0)->maxValue(86400)->default(30)->required(),
            TextInput::make('event_id')->label(__('lighting.rule.event_id'))->numeric()->integer()->minValue(1)->helperText(__('lighting.rule.scope_hint')),
            TextInput::make('tournament_id')->label(__('lighting.rule.tournament_id'))->numeric()->integer()->minValue(1),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('trigger')->label(__('lighting.rule.trigger'))->badge()->formatStateUsing(fn (LightingTrigger $state) => $state->label()),
                TextColumn::make('scene.name')->label(__('lighting.rule.scene')),
                // Inline columns bypass model policies, so gate the toggle explicitly.
                ToggleColumn::make('active')->label(__('lighting.rule.active'))
                    ->disabled(fn (LightingRule $record): bool => ! auth()->user()?->can('update', $record)),
                TextColumn::make('cooldown_seconds')->label(__('lighting.rule.cooldown'))->suffix(' s'),
                TextColumn::make('last_fired_at')->label(__('lighting.rule.last_fired'))->dateTime()->placeholder('-'),
            ])
            ->recordActions([EditAction::make(), DeleteAction::make()])
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLightingRules::route('/'),
            'create' => CreateLightingRule::route('/create'),
            'edit' => EditLightingRule::route('/{record}/edit'),
        ];
    }
}
