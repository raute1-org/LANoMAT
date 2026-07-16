<?php

namespace App\Modules\Infoscreen\Filament\Resources\InfoscreenScenes\Tables;

use App\Modules\Infoscreen\Actions\ShowSceneNow;
use App\Modules\Infoscreen\Enums\SceneType;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Table;

class InfoscreenScenesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('type')
                    ->label(__('infoscreen.fields.type'))
                    ->badge()
                    ->formatStateUsing(fn (SceneType $state) => $state->label()),
                TextColumn::make('event.name')
                    ->label(__('infoscreen.fields.event'))
                    ->searchable(),
                TextColumn::make('duration_sec')
                    ->label(__('infoscreen.fields.duration_sec')),
                ToggleColumn::make('enabled')
                    ->label(__('infoscreen.fields.enabled'))
                    // Inline editable columns bypass Model Policies and only
                    // respect `disabled()` (see Filament docs: Advanced > Security >
                    // Inline editable columns). Gate it behind the `update` policy
                    // explicitly so this doesn't silently rely on panel access alone.
                    ->disabled(fn (InfoscreenScene $record): bool => ! auth()->user()?->can('update', $record)),
            ])
            ->defaultSort('sort')
            ->reorderable('sort')
            ->filters([
                //
            ])
            ->recordActions([
                Action::make('show_now')
                    ->label(__('infoscreen.control.show_now'))
                    ->icon('heroicon-o-tv')
                    ->authorize('showNow')
                    ->requiresConfirmation()
                    ->action(function (InfoscreenScene $record): void {
                        app(ShowSceneNow::class)->handle($record);

                        Notification::make()
                            ->title(__('infoscreen.control.shown'))
                            ->success()
                            ->send();
                    }),
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
