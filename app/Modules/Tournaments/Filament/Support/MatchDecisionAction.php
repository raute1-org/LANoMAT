<?php

namespace App\Modules\Tournaments\Filament\Support;

use App\Modules\Tournaments\Actions\OverrideMatchResult;
use App\Modules\Tournaments\Exceptions\StaleMatchException;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\GameMatch;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * The orga "decide this match" action, shared by the tournament's match list
 * (every state with two entrants) and the dispute queue. Scores are
 * entry-oriented ("Anmeldung 1 : Anmeldung 2"); a reason is mandatory and ends
 * up in the audit row written by {@see OverrideMatchResult}.
 */
class MatchDecisionAction
{
    public static function make(string $name, string $labelKey): Action
    {
        return Action::make($name)
            ->label(__($labelKey))
            ->icon('heroicon-o-flag')
            ->authorize('decideMatch')
            ->requiresConfirmation()
            ->modalDescription(__('tournaments.admin.matches.decide_description'))
            ->schema([
                TextInput::make('score1')
                    ->label(__('tournaments.admin.disputes.score1'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999),
                TextInput::make('score2')
                    ->label(__('tournaments.admin.disputes.score2'))
                    ->required()
                    ->numeric()
                    ->integer()
                    ->minValue(0)
                    ->maxValue(9999)
                    // A match cannot end in a tie (see BracketProgressor::apply()'s
                    // "no draws" domain rule) — reject it here so the form never
                    // submits one in the first place.
                    ->different('score1')
                    ->validationMessages([
                        'different' => __('tournaments.errors.tied_score'),
                    ]),
                Textarea::make('reason')
                    ->label(__('tournaments.admin.matches.reason'))
                    ->helperText(__('tournaments.admin.matches.reason_helper'))
                    ->required()
                    ->minLength(3)
                    ->maxLength(500)
                    ->rows(2),
            ])
            ->action(function (GameMatch $record, array $data): void {
                try {
                    app(OverrideMatchResult::class)->handle(
                        $record,
                        (int) $data['score1'],
                        (int) $data['score2'],
                        (string) $data['reason'],
                    );
                } catch (TournamentException|StaleMatchException $exception) {
                    Notification::make()
                        ->title(__($exception->translationKey))
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('tournaments.admin.matches.decided'))
                    ->success()
                    ->send();
            });
    }
}
