<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\Filament\Resources\WebVoiceChannels\Tables;

use App\Models\User;
use App\Modules\WebVoice\Actions\SetChannelArchived;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Domain\ChannelKind;
use App\Modules\WebVoice\Domain\RoomParticipant;
use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class WebVoiceChannelsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('web_voice.resource.name'))
                    ->searchable(),
                TextColumn::make('kind')
                    ->label(__('web_voice.resource.kind'))
                    ->formatStateUsing(fn (ChannelKind $state): string => __('web_voice.resource.kind_'.$state->value))
                    ->badge(),
                TextColumn::make('description')
                    ->label(__('web_voice.resource.description'))
                    ->limit(60)
                    ->toggleable(),
                // Live occupancy straight from the SFU, refreshed by the table poll. One call per
                // visible, non-archived row; an unreachable server shows a note instead of failing the page.
                TextColumn::make('occupants')
                    ->label(__('web_voice.resource.occupants'))
                    ->state(fn (WebVoiceChannel $record): string => self::occupants($record)),
                IconColumn::make('archived_at')
                    ->label(__('web_voice.resource.archived'))
                    ->boolean()
                    ->state(fn (WebVoiceChannel $record): bool => $record->isArchived()),
            ])
            ->defaultSort('id', 'desc')
            ->poll('10s')
            ->recordActions([
                EditAction::make()
                    ->authorize(fn (WebVoiceChannel $record): bool => $record->isGeneral() && Gate::allows('manage', WebVoiceChannel::class)),
                Action::make('archive')
                    ->label(__('web_voice.resource.archive'))
                    ->icon('heroicon-o-archive-box')
                    ->visible(fn (WebVoiceChannel $record): bool => ! $record->isArchived() && Gate::allows('manage', WebVoiceChannel::class))
                    ->requiresConfirmation()
                    ->action(fn (WebVoiceChannel $record) => app(SetChannelArchived::class)->handle(self::admin(), $record, true)),
                Action::make('restore')
                    ->label(__('web_voice.resource.restore'))
                    ->icon('heroicon-o-arrow-uturn-left')
                    ->visible(fn (WebVoiceChannel $record): bool => $record->isArchived() && Gate::allows('manage', WebVoiceChannel::class))
                    ->action(fn (WebVoiceChannel $record) => app(SetChannelArchived::class)->handle(self::admin(), $record, false)),
            ]);
    }

    private static function occupants(WebVoiceChannel $channel): string
    {
        if ($channel->isArchived()) {
            return '-';
        }

        try {
            $names = array_map(
                fn (RoomParticipant $p): string => $p->name !== '' ? $p->name : $p->identity,
                app(WebVoiceClient::class)->participants($channel->roomName()),
            );
        } catch (WebVoiceUnavailable) {
            return __('web_voice.resource.unreachable');
        }

        return $names === [] ? __('web_voice.resource.nobody') : implode(', ', $names);
    }

    private static function admin(): User
    {
        /** @var User $user */
        $user = Auth::user();

        return $user;
    }
}
