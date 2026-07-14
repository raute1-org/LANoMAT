<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\RelationManagers;

use App\Modules\Tournaments\Actions\CheckInEntry;
use App\Modules\Tournaments\Actions\WithdrawEntry;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\TournamentEntry;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

class EntriesRelationManager extends RelationManager
{
    protected static string $relationship = 'entries';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('tournaments.admin.entries.title');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('display_name')
                    ->label(__('tournaments.admin.entries.display_name'))
                    ->searchable(),
                TextColumn::make('team.name')
                    ->label(__('tournaments.admin.entries.team'))
                    ->placeholder('—'),
                TextColumn::make('user.name')
                    ->label(__('tournaments.admin.entries.user'))
                    ->placeholder('—'),
                TextColumn::make('status')
                    ->label(__('tournaments.admin.entries.status'))
                    ->badge()
                    ->formatStateUsing(fn (EntryStatus $state) => $state->label()),
                IconColumn::make('checked_in_at')
                    ->label(__('tournaments.admin.entries.checked_in'))
                    ->boolean()
                    ->state(fn (TournamentEntry $record) => $record->checked_in_at !== null),
            ])
            ->recordActions([
                Action::make('check_in')
                    ->label(__('tournaments.admin.entries.check_in'))
                    // checkIn() policy also allows the entry's own
                    // user/team-owner; in this orga-only panel it's always an
                    // orga acting, but the explicit ->authorize() is kept per
                    // the "every Filament action gets an authorize()" rule.
                    ->authorize('checkIn')
                    ->visible(fn (TournamentEntry $record) => $record->status !== EntryStatus::CheckedIn
                        && $record->status !== EntryStatus::Withdrawn)
                    ->action(function (TournamentEntry $record): void {
                        try {
                            app(CheckInEntry::class)->handle($record);
                        } catch (TournamentException $exception) {
                            Notification::make()
                                ->title(__($exception->translationKey))
                                ->danger()
                                ->send();
                        }
                    }),
                Action::make('withdraw')
                    ->label(__('tournaments.admin.entries.withdraw'))
                    // There is no entry-level "withdraw" policy method (only
                    // participants withdraw themselves via the public flow);
                    // the admin action is orga-only, authorized via the
                    // owning tournament's manage() ability.
                    ->authorize(fn (TournamentEntry $record): bool => Gate::allows('manage', $record->tournament))
                    ->visible(fn (TournamentEntry $record) => $record->status !== EntryStatus::Withdrawn)
                    ->requiresConfirmation()
                    ->action(function (TournamentEntry $record): void {
                        try {
                            app(WithdrawEntry::class)->handle($record);
                        } catch (TournamentException $exception) {
                            Notification::make()
                                ->title(__($exception->translationKey))
                                ->danger()
                                ->send();
                        }
                    }),
            ]);
    }
}
