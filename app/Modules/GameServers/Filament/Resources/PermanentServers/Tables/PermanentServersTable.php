<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\PermanentServers\Tables;

use App\Models\User;
use App\Modules\GameServers\Actions\DeletePermanentServer;
use App\Modules\GameServers\Actions\PowerPermanentServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\PermanentServerRefresher;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Throwable;

class PermanentServersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('title')
                    ->label(__('gameservers.permanent.fields.title'))
                    ->description(fn (?ServerLink $record): ?string => $record?->game?->name)
                    ->searchable(),
                TextColumn::make('source')
                    ->label(__('gameservers.permanent.fields.source'))
                    ->badge()
                    ->formatStateUsing(fn (ServerSource $state): string => $state->label()),
                TextColumn::make('status')
                    ->label(__('gameservers.fields.status'))
                    ->badge()
                    ->color(fn (ServerLinkStatus $state): string => match ($state) {
                        ServerLinkStatus::Ready => 'success',
                        ServerLinkStatus::Failed, ServerLinkStatus::InstallFailed, ServerLinkStatus::Offline => 'danger',
                        default => 'warning',
                    })
                    ->formatStateUsing(fn (ServerLinkStatus $state): string => $state->label()),
                TextColumn::make('join_info_address')
                    ->label(__('gameservers.permanent.fields.address'))
                    ->getStateUsing(function (?ServerLink $record): ?string {
                        $info = $record?->join_info;

                        return $info?->address === null ? null : $info->address.($info->port !== null ? ':'.$info->port : '');
                    })
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('players')
                    ->label(__('gameservers.permanent.fields.players'))
                    ->getStateUsing(fn (?ServerLink $record): ?string => $record !== null && is_array($record->query_info)
                        ? ($record->query_info['players'] ?? 0).'/'.($record->query_info['max_players'] ?? 0)
                        : null)
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('pelican_node_id')
                    ->label(__('gameservers.permanent.fields.node'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
            ])
            ->defaultSort('title')
            ->recordActions([
                self::power('start', PowerAction::Start, 'heroicon-o-play'),
                self::power('stop', PowerAction::Stop, 'heroicon-o-stop'),
                self::power('restart', PowerAction::Restart, 'heroicon-o-arrow-path'),
                Action::make('refresh')
                    ->label(__('gameservers.permanent.actions.refresh'))
                    ->icon('heroicon-o-signal')
                    ->authorize('update')
                    ->action(function (ServerLink $record): void {
                        app(PermanentServerRefresher::class)->refresh($record);

                        Notification::make()->title(__('gameservers.permanent.actions.refreshed'))->success()->send();
                    }),
                Action::make('remove')
                    ->label(__('gameservers.permanent.actions.delete'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->modalDescription(fn (ServerLink $record): string => $record->source === ServerSource::Pelican
                        ? __('gameservers.permanent.actions.delete_pelican_help')
                        : __('gameservers.permanent.actions.delete_other_help'))
                    ->action(function (ServerLink $record): void {
                        $user = Auth::user();

                        if (! $user instanceof User) {
                            return;
                        }

                        try {
                            app(DeletePermanentServer::class)->handle($user, $record);
                        } catch (GameServerException $e) {
                            Notification::make()->title(__($e->translationKey))->danger()->send();

                            return;
                        } catch (Throwable) {
                            Notification::make()->title(__('gameservers.actions.power_failed'))->danger()->send();

                            return;
                        }

                        Notification::make()->title(__('gameservers.permanent.actions.deleted'))->success()->send();
                    }),
            ]);
    }

    /** A power action, offered only for sources LANoMAT can control (not for external servers). */
    private static function power(string $name, PowerAction $power, string $icon): Action
    {
        return Action::make($name)
            ->label(__("gameservers.actions.{$name}"))
            ->icon($icon)
            ->authorize('power')
            ->requiresConfirmation()
            ->visible(fn (?ServerLink $record): bool => $record?->source->canControl() ?? false)
            ->action(function (ServerLink $record) use ($power): void {
                $user = Auth::user();

                if (! $user instanceof User) {
                    return;
                }

                try {
                    app(PowerPermanentServer::class)->handle($user, $record, $power);
                } catch (GameServerException $e) {
                    Notification::make()->title(__($e->translationKey))->danger()->send();

                    return;
                } catch (Throwable) {
                    Notification::make()->title(__('gameservers.actions.power_failed'))->danger()->send();

                    return;
                }

                Notification::make()->title(__('gameservers.actions.power_sent'))->success()->send();
            });
    }
}
