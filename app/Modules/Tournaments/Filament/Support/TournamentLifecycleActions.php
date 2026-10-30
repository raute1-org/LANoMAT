<?php

namespace App\Modules\Tournaments\Filament\Support;

use App\Modules\Tournaments\Actions\OpenCheckin;
use App\Modules\Tournaments\Actions\OpenTournamentEnrollment;
use App\Modules\Tournaments\Actions\ReopenToDraft;
use App\Modules\Tournaments\Actions\StartTournament;
use App\Modules\Tournaments\Enums\EntryStatus;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use Closure;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

/**
 * The tournament lifecycle as Filament actions, shared by the edit page's
 * header and the list table's row actions so both offer identical,
 * status-conditioned next steps:
 *
 *   draft --openEnrollment--> enrollment --openCheckin--> check_in --start--> live
 *   enrollment|check_in --closeEnrollment (no entries only)--> draft
 *
 * Every action is policy-gated via `manage` and delegates to an Action class
 * (which owns the row lock and the guards).
 */
class TournamentLifecycleActions
{
    /** @return list<Action> */
    public static function make(): array
    {
        return [
            self::openEnrollment(),
            self::openCheckin(),
            self::closeEnrollment(),
            self::start(),
        ];
    }

    private static function openEnrollment(): Action
    {
        return self::build(
            'openEnrollment',
            'open_enrollment',
            'enrollment_opened',
            fn (Tournament $record) => app(OpenTournamentEnrollment::class)->handle($record),
            fn (Tournament $record): bool => $record->status === TournamentStatus::Draft,
        )->color('primary')->icon('heroicon-o-lock-open');
    }

    private static function openCheckin(): Action
    {
        return self::build(
            'openCheckin',
            'open_checkin',
            'checkin_opened',
            fn (Tournament $record) => app(OpenCheckin::class)->handle($record),
            fn (Tournament $record): bool => $record->status === TournamentStatus::Enrollment,
        )->color('gray')->icon('heroicon-o-clipboard-document-check');
    }

    private static function closeEnrollment(): Action
    {
        return self::build(
            'closeEnrollment',
            'close_enrollment',
            'enrollment_closed',
            fn (Tournament $record) => app(ReopenToDraft::class)->handle($record),
            // Only offered while it can actually succeed: with entries the
            // next step is the check-in phase instead.
            fn (Tournament $record): bool => in_array($record->status, [TournamentStatus::Enrollment, TournamentStatus::CheckIn], true)
                && ! $record->entries()->where('status', '!=', EntryStatus::Withdrawn->value)->exists(),
        )->color('gray')->icon('heroicon-o-lock-closed');
    }

    private static function start(): Action
    {
        return self::build(
            'start',
            'start',
            'started',
            fn (Tournament $record) => app(StartTournament::class)->handle($record),
            // Mirrors StartTournament's own guard so the button is never a dead click.
            fn (Tournament $record): bool => in_array($record->status, [TournamentStatus::Enrollment, TournamentStatus::CheckIn], true),
            confirmable: false,
        )->color('success')->icon('heroicon-o-play');
    }

    /**
     * @param  Closure(Tournament): mixed  $handler
     * @param  Closure(Tournament): bool  $visible
     */
    private static function build(string $name, string $labelKey, string $doneKey, Closure $handler, Closure $visible, bool $confirmable = true): Action
    {
        $action = Action::make($name)
            ->label(__("tournaments.admin.actions.{$labelKey}"))
            ->authorize('manage')
            ->visible($visible)
            ->requiresConfirmation()
            ->action(function (Tournament $record, Component $livewire) use ($handler, $doneKey): void {
                try {
                    $handler($record);
                } catch (TournamentException $exception) {
                    Notification::make()
                        ->title(__($exception->translationKey, $exception->translationParams))
                        ->danger()
                        ->send();

                    return;
                }

                // The Action classes lock and save a fresh instance, so the
                // in-memory record is stale: refresh it so the status badge
                // and the form header re-render the new status.
                $record->refresh();

                Notification::make()
                    ->title(__("tournaments.admin.actions.{$doneKey}"))
                    ->success()
                    ->send();

                if (method_exists($livewire, 'refreshFormData')) {
                    $livewire->refreshFormData(['status']);
                }
            });

        if ($confirmable && __("tournaments.admin.actions.{$labelKey}_description") !== "tournaments.admin.actions.{$labelKey}_description") {
            $action->modalDescription(__("tournaments.admin.actions.{$labelKey}_description"));
        }

        return $action;
    }
}
