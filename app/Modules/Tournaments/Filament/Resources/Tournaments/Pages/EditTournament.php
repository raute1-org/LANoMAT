<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Actions\SetTournamentWinner;
use App\Modules\Tournaments\Domain\RoundRobinStandings;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Enums\TournamentStatus;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Filament\Resources\Tournaments\TournamentResource;
use App\Modules\Tournaments\Filament\Support\TournamentLifecycleActions;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Support\MatchProgression;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\HtmlString;

class EditTournament extends EditRecord
{
    protected static string $resource = TournamentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ...TournamentLifecycleActions::make(),
            self::setWinnerAction(),
            // `delete` (not `manage`): the policy refuses live and finished
            // tournaments, which would erase every match and result.
            DeleteAction::make()
                ->authorize('delete')
                ->visible(fn (Tournament $record): bool => auth()->user()?->can('delete', $record) ?? false),
        ];
    }

    /**
     * The current lifecycle status as a native Filament badge right under the
     * title; the next-step actions sit beside it in the header.
     */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var Tournament $record */
        $record = $this->getRecord();

        return new HtmlString(Blade::render(
            '<x-filament::badge :color="$color">{{ $label }}</x-filament::badge>',
            ['color' => $record->status->color(), 'label' => $record->status->label()],
        ));
    }

    /**
     * A round robin that ended level at the top stays live until the orga
     * names the winner among the tied leaders (standings show "Gleichstand").
     */
    private static function setWinnerAction(): Action
    {
        return Action::make('setWinner')
            ->label(__('tournaments.admin.winner.action'))
            ->icon('heroicon-o-trophy')
            ->authorize('manage')
            ->visible(fn (Tournament $record): bool => self::tiedLeaders($record) !== [])
            ->modalDescription(__('tournaments.admin.winner.description'))
            ->schema([
                Select::make('entry_id')
                    ->label(__('tournaments.admin.winner.entry'))
                    ->required()
                    ->options(fn (Tournament $record): array => TournamentEntry::query()
                        ->whereIn('id', self::tiedLeaders($record))
                        ->pluck('display_name', 'id')
                        ->all()),
            ])
            ->action(function (Tournament $record, array $data): void {
                try {
                    app(SetTournamentWinner::class)->handle($record, (int) $data['entry_id']);
                } catch (TournamentException $exception) {
                    Notification::make()->title(__($exception->translationKey))->danger()->send();

                    return;
                }

                Notification::make()->title(__('tournaments.admin.winner.set'))->success()->send();
            });
    }

    /** @return list<int> the tied rank-1 entries of a fully played, still live round robin */
    private static function tiedLeaders(Tournament $tournament): array
    {
        if ($tournament->status !== TournamentStatus::Live || $tournament->format !== TournamentFormat::RoundRobin) {
            return [];
        }

        $plan = app(MatchProgression::class)->reconstruct(
            GameMatch::query()->where('tournament_id', $tournament->id)->get()->keyBy('id'),
        );

        $leaders = RoundRobinStandings::leaders($plan);

        return RoundRobinStandings::isComplete($plan) && count($leaders) > 1 ? $leaders : [];
    }
}
