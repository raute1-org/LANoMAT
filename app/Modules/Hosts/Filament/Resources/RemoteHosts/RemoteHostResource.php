<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts;

use App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages\CreateRemoteHost;
use App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages\EditRemoteHost;
use App\Modules\Hosts\Filament\Resources\RemoteHosts\Pages\ListRemoteHosts;
use App\Modules\Hosts\Filament\Resources\RemoteHosts\Schemas\RemoteHostForm;
use App\Modules\Hosts\Filament\Resources\RemoteHosts\Tables\RemoteHostsTable;
use App\Modules\Hosts\Models\RemoteHost;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga-only registry of managed remote hosts (M7's IP+SSH-key model — see
 * CLAUDE.md's M7 through-line). This resource is persistence + admin UI
 * only; Task 2 adds the SSH executor that actually connects out.
 */
class RemoteHostResource extends Resource
{
    protected static ?string $model = RemoteHost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServer;

    // No dedicated "Infra" navigation group exists yet (see
    // AdminNavigationGroup); this mirrors ServerLinkResource's precedent of
    // filing infra-adjacent resources under TurniereUndTeams rather than
    // growing the shared enum for a single M7 resource.
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 40;

    public static function getModelLabel(): string
    {
        return __('hosts.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('hosts.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return RemoteHostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RemoteHostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRemoteHosts::route('/'),
            'create' => CreateRemoteHost::route('/create'),
            'edit' => EditRemoteHost::route('/{record}/edit'),
        ];
    }
}
