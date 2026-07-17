<?php

declare(strict_types=1);

namespace App\Modules\CustomServers\Filament\Resources\CustomServers\Tables;

use App\Models\User;
use App\Modules\CustomServers\Actions\ProbeCustomServer;
use App\Modules\CustomServers\Actions\StartCustomServer;
use App\Modules\CustomServers\Actions\StopCustomServer;
use App\Modules\CustomServers\Enums\CustomServerStatus;
use App\Modules\CustomServers\Models\CustomServer;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Support\Facades\Auth;
use Throwable;

class CustomServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('customservers.fields.name'))
                    ->searchable(),
                TextColumn::make('host.name')
                    ->label(__('customservers.fields.host')),
                TextColumn::make('event.name')
                    ->label(__('customservers.fields.event'))
                    ->placeholder('—'),
                TextColumn::make('image')
                    ->label(__('customservers.fields.image'))
                    ->fontFamily(FontFamily::Mono)
                    ->searchable(),
                TextColumn::make('container_name')
                    ->label(__('customservers.fields.container_name'))
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('status')
                    ->label(__('customservers.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (CustomServerStatus $state): string => $state->label()),
                TextColumn::make('last_output')
                    ->label(__('customservers.fields.last_output'))
                    ->fontFamily(FontFamily::Mono)
                    ->limit(60)
                    ->placeholder('—')
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(__('customservers.actions.start'))
                    ->icon('heroicon-o-play')
                    ->authorize('start')
                    ->requiresConfirmation()
                    ->action(fn (CustomServer $record) => self::run(
                        fn () => app(StartCustomServer::class)->handle($record, self::actor()),
                        __('customservers.actions.started'),
                        __('customservers.actions.start_failed'),
                    )),
                Action::make('stop')
                    ->label(__('customservers.actions.stop'))
                    ->icon('heroicon-o-stop')
                    ->color('danger')
                    ->authorize('stop')
                    ->requiresConfirmation()
                    ->action(fn (CustomServer $record) => self::run(
                        fn () => app(StopCustomServer::class)->handle($record, self::actor()),
                        __('customservers.actions.stopped'),
                        __('customservers.actions.stop_failed'),
                    )),
                Action::make('probe')
                    ->label(__('customservers.actions.probe'))
                    ->icon('heroicon-o-arrow-path')
                    ->authorize('view')
                    ->action(fn (CustomServer $record) => self::run(
                        fn () => app(ProbeCustomServer::class)->handle($record, self::actor()),
                        __('customservers.actions.started'),
                        __('customservers.actions.start_failed'),
                    )),
                EditAction::make()
                    ->authorize('update'),
                DeleteAction::make()
                    ->authorize('delete'),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Resolves the authenticated actor for Start/Stop/Probe actions.
     * Filament's `authMiddleware` (see AdminPanelProvider) guarantees a
     * logged-in user reaches this table at all, so a null here would
     * indicate the framework's own auth guarantee was broken — surfacing
     * that as an AuthenticationException rather than silently widening
     * these actions' `User` (not `?User`) parameter to accept null.
     */
    private static function actor(): User
    {
        $user = Auth::user();

        if (! $user instanceof User) {
            throw new AuthenticationException;
        }

        return $user;
    }

    /**
     * Runs a lifecycle action and surfaces any failure as a danger
     * notification instead of a 500 — mirrors ServerLinksTable::power().
     */
    private static function run(callable $action, string $successTitle, string $failureTitle): void
    {
        try {
            $action();
        } catch (Throwable) {
            Notification::make()
                ->title($failureTitle)
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title($successTitle)
            ->success()
            ->send();
    }
}
