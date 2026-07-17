<?php

declare(strict_types=1);

namespace App\Modules\Files\Filament\Resources\SharedFiles;

use App\Modules\Files\Filament\Resources\SharedFiles\Pages\ListSharedFiles;
use App\Modules\Files\Filament\Resources\SharedFiles\Tables\SharedFilesTable;
use App\Modules\Files\Models\SharedFile;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga review queue for LAN file-sharing uploads (roadmap 7.3): orga sees
 * ALL files regardless of visibility (SharedFilePolicy::viewAny = isOrga),
 * moderates pending ones via the Freigeben/Ablehnen row actions. No
 * create/edit page — rows are only ever produced by UploadSharedFile, never
 * by hand in the admin panel (mirrors ServerLinkResource's and
 * RemoteHostResource's precedent of filing infra/moderation-adjacent
 * resources under TurniereUndTeams rather than growing the shared
 * navigation-group enum for a single resource).
 */
class SharedFileResource extends Resource
{
    protected static ?string $model = SharedFile::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFolderOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 41;

    public static function getModelLabel(): string
    {
        return __('files.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('files.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('files.resource.plural_label');
    }

    public static function table(Table $table): Table
    {
        return SharedFilesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSharedFiles::route('/'),
        ];
    }
}
