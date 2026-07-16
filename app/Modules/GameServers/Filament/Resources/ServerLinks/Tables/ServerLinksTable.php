<?php

declare(strict_types=1);

namespace App\Modules\GameServers\Filament\Resources\ServerLinks\Tables;

use App\Modules\GameServers\Actions\DeprovisionServer;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Models\ServerLink;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Throwable;

class ServerLinksTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('match_or_tournament')
                    ->label(__('gameservers.fields.match_or_tournament'))
                    ->getStateUsing(fn (?ServerLink $record): string => $record !== null ? self::describeOwner($record) : '—'),
                TextColumn::make('pelican_server_id')
                    ->label(__('gameservers.fields.pelican_server_id'))
                    ->fontFamily(FontFamily::Mono)
                    ->copyable()
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('gameservers.fields.status'))
                    ->badge()
                    ->formatStateUsing(fn (ServerLinkStatus $state): string => $state->label()),
                TextColumn::make('join_info_address')
                    ->label(__('gameservers.fields.address'))
                    ->getStateUsing(fn (?ServerLink $record): ?string => $record?->join_info->address)
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—'),
                TextColumn::make('pelican_panel')
                    ->label(__('gameservers.fields.pelican_panel'))
                    ->state(__('gameservers.fields.open_in_pelican'))
                    ->color('primary')
                    ->url(
                        fn (?ServerLink $record): ?string => $record !== null && $record->pelican_server_id !== null
                            ? rtrim((string) config('services.pelican.panel_url'), '/')."/server/{$record->pelican_server_id}"
                            : null,
                        shouldOpenInNewTab: true,
                    )
                    ->visible(fn (?ServerLink $record): bool => $record?->pelican_server_id !== null),
            ])
            ->recordActions([
                Action::make('start')
                    ->label(__('gameservers.actions.start'))
                    ->icon('heroicon-o-play')
                    ->authorize('power')
                    ->requiresConfirmation()
                    ->visible(fn (?ServerLink $record): bool => $record?->pelican_server_id !== null)
                    ->action(fn (ServerLink $record) => self::power($record, PowerAction::Start)),
                Action::make('stop')
                    ->label(__('gameservers.actions.stop'))
                    ->icon('heroicon-o-stop')
                    ->authorize('power')
                    ->requiresConfirmation()
                    ->visible(fn (?ServerLink $record): bool => $record?->pelican_server_id !== null)
                    ->action(fn (ServerLink $record) => self::power($record, PowerAction::Stop)),
                Action::make('restart')
                    ->label(__('gameservers.actions.restart'))
                    ->icon('heroicon-o-arrow-path')
                    ->authorize('power')
                    ->requiresConfirmation()
                    ->visible(fn (?ServerLink $record): bool => $record?->pelican_server_id !== null)
                    ->action(fn (ServerLink $record) => self::power($record, PowerAction::Restart)),
                Action::make('deprovision')
                    ->label(__('gameservers.actions.deprovision'))
                    ->icon('heroicon-o-trash')
                    ->color('danger')
                    ->authorize('delete')
                    ->requiresConfirmation()
                    ->visible(fn (?ServerLink $record): bool => $record?->status !== ServerLinkStatus::Stopped)
                    ->action(function (ServerLink $record): void {
                        app(DeprovisionServer::class)->handle($record);

                        Notification::make()
                            ->title(__('gameservers.actions.deprovisioned'))
                            ->success()
                            ->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /**
     * Sends a power signal to the linked Pelican server and surfaces a
     * GameServerException as a danger notification instead of a 500 —
     * mirrors EditTournament's `start` header action.
     */
    private static function power(ServerLink $record, PowerAction $action): void
    {
        if ($record->pelican_server_id === null) {
            return;
        }

        try {
            app(PelicanClient::class)->powerAction($record->pelican_server_id, $action);
        } catch (GameServerException $e) {
            Notification::make()
                ->title(__($e->translationKey))
                ->danger()
                ->send();

            return;
        } catch (Throwable) {
            Notification::make()
                ->title(__('gameservers.actions.power_failed'))
                ->danger()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('gameservers.actions.power_sent'))
            ->success()
            ->send();
    }

    private static function describeOwner(ServerLink $record): string
    {
        if ($record->match_id !== null) {
            return __('gameservers.fields.match_label', ['id' => $record->match_id]);
        }

        $tournament = $record->tournament;

        if ($tournament !== null) {
            return $tournament->name;
        }

        return '—';
    }
}
