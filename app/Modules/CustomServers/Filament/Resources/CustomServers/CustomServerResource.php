<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers;

use App\Modules\CustomServers\Filament\Resources\CustomServers\Pages\CreateCustomServer;
use App\Modules\CustomServers\Filament\Resources\CustomServers\Pages\EditCustomServer;
use App\Modules\CustomServers\Filament\Resources\CustomServers\Pages\ListCustomServers;
use App\Modules\CustomServers\Filament\Resources\CustomServers\Schemas\CustomServerForm;
use App\Modules\CustomServers\Filament\Resources\CustomServers\Tables\CustomServersTable;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga-only "escape hatch" for docker game servers Pelican doesn't cover
 * (roadmap 7.4): a plain `docker run` composed from structured fields and
 * executed on a registered {@see RemoteHost} via
 * the SSH {@see RemoteExecutor}.
 */
class CustomServerResource extends Resource
{
    protected static ?string $model = CustomServer::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    // No dedicated "Infra" navigation group exists yet (see
    // RemoteHostResource's precedent for the same reasoning).
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 41;

    public static function getModelLabel(): string
    {
        return __('customservers.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('customservers.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return CustomServerForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CustomServersTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCustomServers::route('/'),
            'create' => CreateCustomServer::route('/create'),
            'edit' => EditCustomServer::route('/{record}/edit'),
        ];
    }
}
