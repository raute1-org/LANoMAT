<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Filament\Support\MatchDecisionAction;
use App\Modules\Tournaments\Models\GameMatch;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Every match of the tournament with the orga's "Ergebnis setzen" action:
 * decides a ready/reported/disputed match (no-show, walkover) or corrects a
 * completed one (Ruling S). Read-only otherwise: matches are created by the
 * bracket generator, never by hand.
 */
class MatchesRelationManager extends RelationManager
{
    protected static string $relationship = 'matches';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments.admin.matches.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('tournaments.admin.matches.model_label'))
            ->pluralModelLabel(__('tournaments.admin.matches.plural_model_label'))
            ->emptyStateHeading(__('tournaments.admin.matches.empty'))
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['entry1', 'entry2']))
            ->defaultSort('round')
            ->columns([
                TextColumn::make('round')
                    ->label(__('tournaments.admin.matches.round')),
                TextColumn::make('bracket')
                    ->label(__('tournaments.admin.matches.bracket'))
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'winners' => __('tournaments.page.winners_bracket'),
                        'losers' => __('tournaments.page.losers_bracket'),
                        default => __('tournaments.page.finals'),
                    }),
                TextColumn::make('entry1.display_name')
                    ->label(__('tournaments.admin.matches.entry1'))
                    ->placeholder('—'),
                TextColumn::make('entry2.display_name')
                    ->label(__('tournaments.admin.matches.entry2'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('tournaments.admin.matches.status'))
                    ->badge()
                    ->formatStateUsing(fn (MatchStatus $state): string => $state->label()),
                TextColumn::make('score')
                    ->label(__('tournaments.admin.matches.score'))
                    ->state(fn (GameMatch $record): ?string => $record->score1 === null ? null : "{$record->score1} : {$record->score2}")
                    ->placeholder('—')
                    ->fontFamily('mono'),
            ])
            ->recordActions([
                MatchDecisionAction::make('decide', 'tournaments.admin.matches.decide')
                    ->visible(fn (GameMatch $record): bool => auth()->user()?->can('decideMatch', $record) ?? false),
            ]);
    }
}
