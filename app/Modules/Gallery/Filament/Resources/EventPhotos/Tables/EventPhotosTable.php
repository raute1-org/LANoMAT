<?php

declare(strict_types=1);

namespace App\Modules\Gallery\Filament\Resources\EventPhotos\Tables;

use App\Models\User;
use App\Modules\Gallery\Actions\ApprovePhoto;
use App\Modules\Gallery\Actions\RejectPhoto;
use App\Modules\Gallery\Actions\ToggleHighlight;
use App\Modules\Gallery\Enums\PhotoVisibility;
use App\Modules\Gallery\Models\EventPhoto;
use Filament\Actions\Action;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\ImageColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;

class EventPhotosTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                ImageColumn::make('thumb_path')
                    ->label(__('gallery.fields.thumbnail'))
                    ->getStateUsing(fn (EventPhoto $record): string => route('gallery.photos.thumb', $record)),
                TextColumn::make('event.name')
                    ->label(__('gallery.fields.event')),
                TextColumn::make('uploader.name')
                    ->label(__('gallery.fields.user')),
                TextColumn::make('caption')
                    ->label(__('gallery.fields.caption')),
                TextColumn::make('visibility')
                    ->label(__('gallery.fields.visibility'))
                    ->badge()
                    ->color(fn (PhotoVisibility $state): string => match ($state) {
                        PhotoVisibility::Pending => 'warning',
                        PhotoVisibility::Approved => 'success',
                        PhotoVisibility::Rejected => 'danger',
                    })
                    ->formatStateUsing(fn (PhotoVisibility $state): string => $state->label()),
                IconColumn::make('is_highlight')
                    ->label(__('gallery.fields.is_highlight'))
                    ->boolean(),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('gallery.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->visible(fn (EventPhoto $record): bool => $record->visibility !== PhotoVisibility::Approved)
                    ->action(function (EventPhoto $record): void {
                        app(ApprovePhoto::class)->handle($record, self::actor());
                    }),
                Action::make('reject')
                    ->label(__('gallery.actions.reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->authorize('reject')
                    ->requiresConfirmation()
                    ->visible(fn (EventPhoto $record): bool => $record->visibility !== PhotoVisibility::Rejected)
                    ->action(function (EventPhoto $record): void {
                        app(RejectPhoto::class)->handle($record, self::actor());
                    }),
                Action::make('toggleHighlight')
                    ->label(__('gallery.actions.highlight'))
                    ->icon('heroicon-o-star')
                    ->color('warning')
                    ->authorize('highlight')
                    ->action(function (EventPhoto $record): void {
                        app(ToggleHighlight::class)->handle($record, self::actor());
                    }),
            ]);
    }

    /**
     * Resolves the authenticated actor for the Freigeben/Ablehnen/Highlight
     * row actions. Filament's `authMiddleware` (see AdminPanelProvider)
     * guarantees a logged-in user reaches this table at all, so a null here
     * would indicate the framework's own auth guarantee was broken —
     * surfacing that as an AuthenticationException rather than silently
     * widening ApprovePhoto/RejectPhoto/ToggleHighlight's `User` (not `?User`)
     * parameter to accept null (mirrors SharedFilesTable::actor()).
     */
    private static function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }
}
