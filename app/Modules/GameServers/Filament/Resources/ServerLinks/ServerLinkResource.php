<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks;

use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\EditServerLink;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Pages\ListServerLinks;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Schemas\ServerLinkForm;
use App\Modules\GameServers\Filament\Resources\ServerLinks\Tables\ServerLinksTable;
use App\Modules\GameServers\Models\ServerLink;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga-only overview of provisioned Pelican game servers (see
 * ServerLinkPolicy: viewAny/power/update/delete all gate on isOrga). No
 * create page — links are only ever produced by the provisioning
 * chain (Task 4's ProvisionMatchServerJob) or the manual join-info fallback,
 * never by hand in the admin panel (mirrors why Seating's SeatResource has no
 * ad hoc single-seat create either).
 */
class ServerLinkResource extends Resource
{
    protected static ?string $model = ServerLink::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedServerStack;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 32;

    public static function getModelLabel(): string
    {
        return __('gameservers.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gameservers.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('gameservers.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return ServerLinkForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return ServerLinksTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListServerLinks::route('/'),
            'edit' => EditServerLink::route('/{record}/edit'),
        ];
    }
}
