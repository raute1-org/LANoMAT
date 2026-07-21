<?php

declare(strict_types=1);

namespace App\Modules\News\Filament\Resources\NewsPosts\Tables;

use App\Models\User;
use App\Modules\News\Models\NewsPost;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

class NewsPostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('news.fields.title'))
                    ->searchable(),
                TextColumn::make('author.name')
                    ->label(__('news.fields.author')),
                IconColumn::make('published_at')
                    ->label(__('news.fields.published'))
                    ->boolean()
                    ->getStateUsing(fn (NewsPost $record): bool => $record->published_at !== null
                        && $record->published_at->lessThanOrEqualTo(now())),
                TextColumn::make('published_at')
                    ->label(__('news.fields.published_at'))
                    ->dateTime()
                    ->placeholder(__('news.state.draft')),
            ])
            ->defaultSort('created_at', 'desc')
            ->recordActions([
                Action::make('publish')
                    ->label(__('news.actions.publish'))
                    ->icon('heroicon-o-eye')
                    ->color('success')
                    ->authorize('publish')
                    ->requiresConfirmation()
                    ->visible(fn (NewsPost $record): bool => $record->published_at === null
                        || $record->published_at->isFuture())
                    ->action(function (NewsPost $record): void {
                        self::authorizeActor($record);
                        $record->forceFill(['published_at' => now()])->save();
                    }),
                Action::make('unpublish')
                    ->label(__('news.actions.unpublish'))
                    ->icon('heroicon-o-eye-slash')
                    ->color('gray')
                    ->authorize('publish')
                    ->requiresConfirmation()
                    ->visible(fn (NewsPost $record): bool => $record->published_at !== null
                        && $record->published_at->lessThanOrEqualTo(now()))
                    ->action(function (NewsPost $record): void {
                        self::authorizeActor($record);
                        $record->forceFill(['published_at' => null])->save();
                    }),
                EditAction::make(),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Filament's `->authorize()` already gates the action's visibility/click,
     * but resolving the actor here mirrors EventPhotosTable::actor() — a
     * defensive check that a logged-in user really is present, surfaced as
     * an AuthenticationException rather than silently no-op'ing.
     */
    private static function authorizeActor(NewsPost $record): void
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }
    }
}
