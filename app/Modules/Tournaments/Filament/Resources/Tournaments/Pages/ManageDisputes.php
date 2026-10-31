<?php

namespace App\Modules\Tournaments\Filament\Resources\Tournaments\Pages;

use App\Modules\Tournaments\Enums\MatchStatus;
use App\Modules\Tournaments\Enums\ReportStatus;
use App\Modules\Tournaments\Filament\Support\MatchDecisionAction;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use UnitEnum;

/**
 * A cross-tournament queue of `Disputed` matches, with an orga-only override
 * action. Deliberately not a resource page (disputes span every tournament,
 * not one), so it lives as a standalone panel page implementing `HasTable`
 * directly instead of via `TournamentResource::getPages()`.
 */
class ManageDisputes extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::TurniereUndTeams;

    protected static ?int $navigationSort = 34;

    protected static ?string $slug = 'tournaments/disputes';

    public function getTitle(): string
    {
        return __('tournaments.admin.disputes.title');
    }

    public static function getNavigationLabel(): string
    {
        return __('tournaments.admin.disputes.title');
    }

    public static function canAccess(): bool
    {
        return auth()->user()?->isOrga() ?? false;
    }

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('tournaments.admin.disputes.model_label'))
            ->pluralModelLabel(__('tournaments.admin.disputes.plural_model_label'))
            ->emptyStateHeading(__('tournaments.admin.disputes.empty'))
            ->query(fn (): Builder => GameMatch::query()
                ->where('status', MatchStatus::Disputed->value)
                ->with(['tournament', 'entry1', 'entry2']))
            ->columns([
                TextColumn::make('tournament.name')
                    ->label(__('tournaments.admin.disputes.tournament')),
                TextColumn::make('round')
                    ->label(__('tournaments.admin.disputes.round')),
                TextColumn::make('entry1.display_name')
                    ->label(__('tournaments.admin.disputes.entry1')),
                TextColumn::make('entry2.display_name')
                    ->label(__('tournaments.admin.disputes.entry2')),
                // The claimed result, entry-oriented ("Anmeldung 1 : Anmeldung 2")
                // like the override form below — the match's own score columns
                // stay empty until a result is confirmed or overridden.
                TextColumn::make('reported_score')
                    ->label(__('tournaments.admin.disputes.reported_score'))
                    ->state(function (GameMatch $record): ?string {
                        $report = self::disputedReport($record);

                        return $report === null ? null : "{$report->score1} : {$report->score2}";
                    })
                    ->fontFamily('mono'),
                TextColumn::make('reported_by')
                    ->label(__('tournaments.admin.disputes.reported_by'))
                    ->state(fn (GameMatch $record): ?string => self::disputedReport($record)?->reportedBy?->name),
                TextColumn::make('reported_at')
                    ->label(__('tournaments.admin.disputes.reported_at'))
                    ->state(fn (GameMatch $record) => self::disputedReport($record)?->created_at)
                    ->dateTime(),
            ])
            ->recordActions([
                MatchDecisionAction::make('override', 'tournaments.admin.disputes.override'),
            ]);
    }

    private static function disputedReport(GameMatch $match): ?MatchReport
    {
        return $match->reports()
            ->where('status', ReportStatus::Disputed->value)
            ->with('reportedBy')
            ->latest('id')
            ->first();
    }

    public function content(Schema $schema): Schema
    {
        return $schema
            ->components([
                EmbeddedTable::make(),
            ]);
    }
}
