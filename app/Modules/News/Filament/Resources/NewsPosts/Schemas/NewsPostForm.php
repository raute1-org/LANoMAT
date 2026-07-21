<?php

declare(strict_types=1);

namespace App\Modules\News\Filament\Resources\NewsPosts\Schemas;

use App\Modules\News\Models\NewsPost;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

/**
 * Deliberately has no `published_at` or `author_id` field: `published_at` is
 * flipped by the publish/unpublish row action (NewsPostsTable) via
 * `forceFill`, and `author_id` is set from the authenticated user in
 * CreateNewsPost — neither is mass-assignable on {@see NewsPost}.
 */
class NewsPostForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('title')
                    ->label(__('news.fields.title'))
                    ->required()
                    ->maxLength(255),
                Textarea::make('body')
                    ->label(__('news.fields.body'))
                    ->required()
                    ->rows(8)
                    ->columnSpanFull(),
            ]);
    }
}
