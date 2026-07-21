<?php

declare(strict_types=1);

namespace App\Modules\News\Filament\Resources\NewsPosts;

use App\Modules\News\Filament\Resources\NewsPosts\Pages\CreateNewsPost;
use App\Modules\News\Filament\Resources\NewsPosts\Pages\EditNewsPost;
use App\Modules\News\Filament\Resources\NewsPosts\Pages\ListNewsPosts;
use App\Modules\News\Filament\Resources\NewsPosts\Schemas\NewsPostForm;
use App\Modules\News\Filament\Resources\NewsPosts\Tables\NewsPostsTable;
use App\Modules\News\Models\NewsPost;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

/**
 * Full orga CRUD for global news posts (roadmap M12) — unlike the
 * moderation-only EventPhotoResource, this one has Create/Edit pages since
 * orga authors posts from scratch. `published_at` is never a form field (see
 * NewsPostForm's docblock); the publish/unpublish row actions in
 * NewsPostsTable flip it via `forceFill`.
 */
class NewsPostResource extends Resource
{
    protected static ?string $model = NewsPost::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedMegaphone;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Event;

    protected static ?int $navigationSort = 15;

    public static function getModelLabel(): string
    {
        return __('news.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('news.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return NewsPostForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return NewsPostsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListNewsPosts::route('/'),
            'create' => CreateNewsPost::route('/create'),
            'edit' => EditNewsPost::route('/{record}/edit'),
        ];
    }
}
