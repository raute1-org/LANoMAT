<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Filament\Resources\EventPhotos;

use App\Modules\Gallery\Filament\Resources\EventPhotos\Pages\ListEventPhotos;
use App\Modules\Gallery\Filament\Resources\EventPhotos\Tables\EventPhotosTable;
use App\Modules\Gallery\Models\EventPhoto;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Orga/helper moderation queue for gallery uploads (roadmap M12): orga sees
 * ALL photos regardless of visibility (EventPhotoPolicy::viewAny = isOrga),
 * helpers moderate pending ones via the Freigeben/Ablehnen row actions and
 * orga toggles highlights. No create/edit page — rows are only ever produced
 * by UploadPhoto, never by hand in the admin panel (mirrors
 * SharedFileResource's precedent).
 */
class EventPhotoResource extends Resource
{
    protected static ?string $model = EventPhoto::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPhoto;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 42;

    public static function getModelLabel(): string
    {
        return __('gallery.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('gallery.resource.plural_label');
    }

    public static function getNavigationLabel(): string
    {
        return __('gallery.resource.plural_label');
    }

    public static function table(Table $table): Table
    {
        return EventPhotosTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventPhotos::route('/'),
        ];
    }
}
