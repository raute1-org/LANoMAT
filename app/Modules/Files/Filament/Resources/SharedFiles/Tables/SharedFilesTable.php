<?php

declare(strict_types=1);

namespace App\Modules\Files\Filament\Resources\SharedFiles\Tables;

use App\Models\User;
use App\Modules\Files\Actions\ApproveSharedFile;
use App\Modules\Files\Actions\RejectSharedFile;
use App\Modules\Files\Enums\FileVisibility;
use App\Modules\Files\Exceptions\FileException;
use App\Modules\Files\Models\SharedFile;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Number;

class SharedFilesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('event.name')
                    ->label(__('files.fields.event')),
                TextColumn::make('uploader.name')
                    ->label(__('files.fields.user')),
                TextColumn::make('original_name')
                    ->label(__('files.fields.original_name'))
                    ->searchable(),
                TextColumn::make('size_bytes')
                    ->label(__('files.fields.size_bytes'))
                    ->fontFamily(FontFamily::Mono)
                    ->formatStateUsing(fn (int $state): string => Number::fileSize($state, precision: 1)),
                TextColumn::make('visibility')
                    ->label(__('files.fields.visibility'))
                    ->badge()
                    ->color(fn (FileVisibility $state): string => match ($state) {
                        FileVisibility::Pending => 'warning',
                        FileVisibility::Approved => 'success',
                        FileVisibility::Rejected => 'danger',
                    })
                    ->formatStateUsing(fn (FileVisibility $state): string => $state->label()),
                TextColumn::make('download')
                    ->label(__('files.page.download'))
                    ->state(__('files.page.download'))
                    ->color('primary')
                    ->url(fn (SharedFile $record): string => route('files.download', $record), shouldOpenInNewTab: true),
            ])
            ->recordActions([
                Action::make('approve')
                    ->label(__('files.actions.approve'))
                    ->icon('heroicon-o-check')
                    ->color('success')
                    ->authorize('approve')
                    ->requiresConfirmation()
                    ->visible(fn (SharedFile $record): bool => $record->visibility !== FileVisibility::Approved)
                    ->action(function (SharedFile $record): void {
                        try {
                            app(ApproveSharedFile::class)->handle($record, self::actor());
                        } catch (FileException $exception) {
                            Notification::make()
                                ->title(__($exception->translationKey))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('reject')
                    ->label(__('files.actions.reject'))
                    ->icon('heroicon-o-x-mark')
                    ->color('danger')
                    ->authorize('reject')
                    ->requiresConfirmation()
                    ->visible(fn (SharedFile $record): bool => $record->visibility !== FileVisibility::Rejected)
                    ->action(function (SharedFile $record): void {
                        try {
                            app(RejectSharedFile::class)->handle($record, self::actor());
                        } catch (FileException $exception) {
                            Notification::make()
                                ->title(__($exception->translationKey))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }

    /**
     * Resolves the authenticated actor for the Freigeben/Ablehnen row
     * actions. Filament's `authMiddleware` (see AdminPanelProvider)
     * guarantees a logged-in user reaches this table at all, so a null here
     * would indicate the framework's own auth guarantee was broken —
     * surfacing that as an AuthenticationException rather than silently
     * widening ApproveSharedFile/RejectSharedFile's `User` (not `?User`)
     * parameter to accept null (mirrors CustomServersTable::actor()).
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
