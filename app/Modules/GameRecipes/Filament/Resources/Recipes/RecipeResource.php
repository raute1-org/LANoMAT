<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\Recipes;

use App\Modules\GameRecipes\Enums\RecipeStatus;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\CreateRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\EditRecipe;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Pages\ListRecipes;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Schemas\RecipeForm;
use App\Modules\GameRecipes\Filament\Resources\Recipes\Tables\RecipesTable;
use App\Modules\GameRecipes\Models\GameRecipe;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

class RecipeResource extends Resource
{
    protected static ?string $model = GameRecipe::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBookOpen;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 48;

    protected static ?string $recordTitleAttribute = 'title';

    /** Request attribute that memoizes the review queue count for one request. */
    private const REVIEW_QUEUE_ATTRIBUTE = 'lanomat.recipes.review_queue';

    public static function getModelLabel(): string
    {
        return __('game_recipes.resource.label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('game_recipes.resource.plural_label');
    }

    public static function form(Schema $schema): Schema
    {
        return RecipeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return RecipesTable::configure($table);
    }

    /** @return Builder<GameRecipe> */
    public static function getEloquentQuery(): Builder
    {
        // The list shows the contributor of contributed drafts and the file
        // state of a shared game; eager-load both so the review queue does not
        // issue one query per row.
        /** @var Builder<GameRecipe> $query */
        $query = parent::getEloquentQuery()->with(['contributor', 'files.sharedFile']);

        return $query;
    }

    /**
     * Contributed drafts still waiting for a reviewer. One count query per
     * request (the sidebar asks again for every Livewire roundtrip), null when
     * the queue is empty so no "0" badge is shown.
     */
    public static function getNavigationBadge(): ?string
    {
        $count = self::reviewQueueCount();

        return $count > 0 ? (string) $count : null;
    }

    public static function getNavigationBadgeColor(): ?string
    {
        return self::reviewQueueCount() > 0 ? 'warning' : null;
    }

    public static function getNavigationBadgeTooltip(): ?string
    {
        return __('game_recipes.navigation.review_queue');
    }

    private static function reviewQueueCount(): int
    {
        $request = request();
        $cached = $request->attributes->get(self::REVIEW_QUEUE_ATTRIBUTE);

        if (is_int($cached)) {
            return $cached;
        }

        $count = GameRecipe::query()
            ->whereNotNull('contributor_user_id')
            ->where('status', RecipeStatus::Draft->value)
            ->count();

        $request->attributes->set(self::REVIEW_QUEUE_ATTRIBUTE, $count);

        return $count;
    }

    public static function getPages(): array
    {
        return [
            'index' => ListRecipes::route('/'),
            'create' => CreateRecipe::route('/create'),
            'edit' => EditRecipe::route('/{record}/edit'),
        ];
    }
}
