<?php

declare(strict_types=1);

namespace App\Modules\Hosts\Filament\Resources\RemoteHosts\Tables;

use App\Modules\Hosts\Enums\HostRole;
use App\Modules\Hosts\Enums\HostStatus;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class RemoteHostsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('hosts.fields.name'))
                    ->searchable(),
                TextColumn::make('hostname')
                    ->label(__('hosts.fields.hostname'))
                    ->searchable(),
                TextColumn::make('ssh_port')
                    ->label(__('hosts.fields.ssh_port')),
                TextColumn::make('ssh_user')
                    ->label(__('hosts.fields.ssh_user')),
                // Never renders the stored key — a fixed masked placeholder,
                // not derived from the (encrypted) attribute value at all.
                TextColumn::make('ssh_private_key_placeholder')
                    ->label(__('hosts.fields.ssh_private_key'))
                    ->state(fn (): string => __('hosts.fields.ssh_private_key_placeholder')),
                TextColumn::make('role')
                    ->label(__('hosts.fields.role'))
                    ->formatStateUsing(fn (HostRole $state): string => $state->label())
                    ->badge(),
                TextColumn::make('event.name')
                    ->label(__('hosts.fields.event'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('hosts.fields.status'))
                    ->formatStateUsing(fn (HostStatus $state): string => $state->label())
                    ->badge(),
                TextColumn::make('last_probed_at')
                    ->label(__('hosts.fields.last_probed_at'))
                    ->dateTime()
                    ->placeholder('—'),
            ])
            ->recordActions([
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
}
