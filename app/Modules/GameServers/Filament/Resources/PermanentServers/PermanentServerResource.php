<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\PermanentServers;

use App\Models\User;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Pages\EditPermanentServer;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Pages\ListPermanentServers;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Schemas\PermanentServerForm;
use App\Modules\GameServers\Filament\Resources\PermanentServers\Tables\PermanentServersTable;
use App\Modules\GameServers\Models\ServerLink;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;
use UnitEnum;

/**
 * Admin-only management of the permanent game servers (all year, independent of
 * events): create from a Pelican preset on a chosen node, register a Docker
 * container or an external server, power them and remove them. Only the
 * actions the source supports are offered (an external server has no power
 * buttons). See ServerLinkPolicy: `managePermanent` and the record abilities
 * check the admin role.
 */
class PermanentServerResource extends Resource
{
    protected static ?string $model = ServerLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 33;

    protected static ?string $slug = 'permanent-servers';

    public static function getModelLabel(): string
    {
        return __('gameservers.permanent.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gameservers.permanent.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('gameservers.permanent.resource.plural_label');
    }

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('managePermanent', ServerLink::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    /** Only permanent servers, never match or event servers. */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('permanent', true);
    }

    public static function form(Schema $schema): Schema
    {
        return PermanentServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PermanentServersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPermanentServers::route('/'),
            'edit' => EditPermanentServer::route('/{record}/edit'),
        ];
    }
}
