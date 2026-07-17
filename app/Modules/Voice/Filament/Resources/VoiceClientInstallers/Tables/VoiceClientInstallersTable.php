<?php

declare(strict_types=1);

namespace App\Modules\Voice\Filament\Resources\VoiceClientInstallers\Tables;

use App\Modules\Voice\Actions\SetCurrentInstaller;
use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceClientInstaller;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class VoiceClientInstallersTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('provider')
                    ->label(__('voice.resource.fields.provider'))
                    ->formatStateUsing(fn (VoiceProvider $state): string => $state->label())
                    ->badge(),
                TextColumn::make('platform')
                    ->label(__('voice.resource.fields.platform'))
                    ->formatStateUsing(fn (VoiceClientPlatform $state): string => $state->label())
                    ->badge(),
                TextColumn::make('version')
                    ->label(__('voice.resource.fields.version'))
                    ->fontFamily('mono'),
                TextColumn::make('original_name')
                    ->label(__('voice.resource.fields.original_name')),
                IconColumn::make('is_current')
                    ->label(__('voice.resource.fields.is_current'))
                    ->boolean(),
            ])
            ->defaultSort('provider')
            ->groups(['provider', 'platform'])
            ->recordActions([
                Action::make('makeCurrent')
                    ->label(__('voice.resource.actions.make_current'))
                    ->icon('heroicon-o-check-badge')
                    ->color('warning')
                    ->authorize('update')
                    ->visible(fn (VoiceClientInstaller $record): bool => ! $record->is_current)
                    ->action(fn (VoiceClientInstaller $record) => app(SetCurrentInstaller::class)->handle($record)),
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
