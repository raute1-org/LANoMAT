<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\LauncherTerminals;

use App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\Pages\ListLauncherTerminals;
use App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\Tables\LauncherTerminalsTable;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * The launcher fleet monitor: every paired gaming terminal with its derived
 * state (green while the heartbeat is fresh), the game it is playing and what
 * it has installed. Read-only by design — the launcher heartbeat is the only
 * writer, so editing a row by hand would just be overwritten 30 seconds later.
 *
 * Orga-only through LauncherTerminalPolicy::viewAny.
 */
class LauncherTerminalResource extends Resource
{
    protected static ?string $model = LauncherTerminal::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedComputerDesktop;

    // Mirrors RemoteHostResource/WebVoiceChannelResource: infra-adjacent
    // resources live in TurniereUndTeams until a dedicated group exists.
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 44;

    protected static ?string $recordTitleAttribute = 'name';

    public static function getModelLabel(): string
    {
        return __('game_recipes.terminals.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('game_recipes.terminals.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('game_recipes.terminals.resource.plural_label');
    }

    public static function table(Table $table): Table
    {
        return LauncherTerminalsTable::configure($table);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** @return Builder<LauncherTerminal> */
    public static function getEloquentQuery(): Builder
    {
        /** @var Builder<LauncherTerminal> $query */
        $query = parent::getEloquentQuery()->with(['token.user']);

        return $query;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLauncherTerminals::route('/'),
        ];
    }
}
