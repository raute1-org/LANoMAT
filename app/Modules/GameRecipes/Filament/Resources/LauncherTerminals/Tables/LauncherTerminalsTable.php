<?php

declare(strict_types=1);

namespace App\Modules\GameRecipes\Filament\Resources\LauncherTerminals\Tables;

use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use Filament\Actions\DeleteAction;
use Filament\Support\Enums\FontFamily;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LauncherTerminalsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')
                    ->label(__('game_recipes.terminals.fields.name'))
                    ->description(fn (LauncherTerminal $record): ?string => trim(implode(' · ', array_filter([
                        $record->os,
                        $record->arch,
                        $record->ip,
                    ]))) ?: null)
                    ->searchable()
                    ->sortable(),
                TextColumn::make('state')
                    ->label(__('game_recipes.terminals.fields.state'))
                    // The stored state is what the terminal reported; the
                    // monitor shows the derived one (stale heartbeat = offline).
                    ->state(fn (LauncherTerminal $record): TerminalState => $record->derivedState())
                    ->badge()
                    ->color(fn (TerminalState $state): string => $state->color())
                    ->formatStateUsing(fn (TerminalState $state): string => $state->label()),
                TextColumn::make('current_game_slug')
                    ->label(__('game_recipes.terminals.fields.game'))
                    ->placeholder('—')
                    ->fontFamily(FontFamily::Mono),
                TextColumn::make('installed_count')
                    ->label(__('game_recipes.terminals.fields.installed'))
                    ->state(fn (LauncherTerminal $record): int => count($record->installedSlugs()))
                    ->badge()
                    ->color('gray')
                    ->tooltip(fn (LauncherTerminal $record): ?string => $record->installedSlugs() === []
                        ? null
                        : implode(', ', $record->installedSlugs())),
                TextColumn::make('version')
                    ->label(__('game_recipes.terminals.fields.version'))
                    ->fontFamily(FontFamily::Mono)
                    ->placeholder('—')
                    ->toggleable(),
                IconColumn::make('gamepad')
                    ->label(__('game_recipes.terminals.fields.gamepad'))
                    ->boolean()
                    ->toggleable(),
                TextColumn::make('last_seen_at')
                    ->label(__('game_recipes.terminals.fields.last_seen'))
                    ->since()
                    ->placeholder('—')
                    ->sortable(),
                TextColumn::make('token.user.name')
                    ->label(__('game_recipes.terminals.fields.paired_user'))
                    ->placeholder('—')
                    ->toggleable(),
            ])
            ->defaultSort('name')
            ->filters([
                SelectFilter::make('state')
                    ->label(__('game_recipes.terminals.fields.state'))
                    ->options(collect(TerminalState::cases())
                        ->mapWithKeys(fn (TerminalState $state): array => [$state->value => $state->label()])
                        ->all())
                    // The filter acts on the reported state column; a stale row
                    // always reads offline above, which is the signal that matters.
                    ->attribute('state'),
            ])
            ->recordActions([
                DeleteAction::make()
                    ->label(__('game_recipes.terminals.fields.remove'))
                    ->visible(fn (LauncherTerminal $record): bool => ! $record->isOnline()),
            ])
            // A monitor, not a report: 15 s is the tick the fleet moves at.
            ->poll('15s');
    }
}
