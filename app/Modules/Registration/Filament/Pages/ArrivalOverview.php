<?php

namespace App\Modules\Registration\Filament\Pages;

use App\Modules\Events\Filament\Resources\Events\EventResource;
use App\Modules\Events\Models\Event;
use App\Modules\Registration\Enums\RegistrationStatus;
use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Filament\Widgets\ArrivalsByDayChart;
use App\Modules\Registration\Filament\Widgets\ArrivalStatsWidget;
use App\Modules\Registration\Filament\Widgets\ExpectedPresentChart;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Support\ArrivalForecast;
use App\Modules\Registration\Support\ArrivalPlanView;
use App\Modules\Registration\Support\EventDays;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Orga view: when do people arrive and leave, and how many are expected on site.
 * Rendering only — the numbers come from {@see ArrivalForecast}.
 */
class ArrivalOverview extends Page implements HasTable
{
    use InteractsWithRecord;
    use InteractsWithTable;

    protected static string $resource = EventResource::class;

    protected string $view = 'filament.pages.arrival-overview';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);
    }

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof Event && (auth()->user()?->can('view', $record) ?? false);
    }

    public function getTitle(): string|Htmlable
    {
        return __('registration.admin.arrival_overview_title', ['event' => $this->eventModel()->name]);
    }

    public function getBreadcrumb(): string
    {
        return __('registration.admin.arrival_overview');
    }

    private function eventModel(): Event
    {
        $record = $this->getRecord();
        assert($record instanceof Event);

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('events.resource.label'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(EventResource::getUrl('edit', ['record' => $this->getRecord()])),
        ];
    }

    /** @return array<string, mixed> */
    public function getWidgetData(): array
    {
        return ['eventId' => $this->eventModel()->id];
    }

    protected function getHeaderWidgets(): array
    {
        return [ArrivalStatsWidget::class, ArrivalsByDayChart::class, ExpectedPresentChart::class];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function table(Table $table): Table
    {
        $event = $this->eventModel();

        return $table
            ->query(fn (): Builder => EventRegistration::query()
                ->with('user')
                ->where('event_id', $event->id)
                ->where('status', '!=', RegistrationStatus::Cancelled->value))
            ->heading(__('registration.admin.title'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('registration.admin.participant'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('arrival_day')
                    ->label(__('registration.admin.arrival_columns.arrival'))
                    ->state(fn (EventRegistration $r): string => ArrivalPlanView::label($r->arrival_day?->toDateString(), $r->arrival_window)
                        ?? __('registration.admin.arrival_columns.open'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::orderByPlan($query, true, $direction)),
                TextColumn::make('departure_day')
                    ->label(__('registration.admin.arrival_columns.departure'))
                    ->state(fn (EventRegistration $r): string => ArrivalPlanView::label($r->departure_day?->toDateString(), $r->departure_window)
                        ?? __('registration.admin.arrival_columns.open'))
                    ->sortable(query: fn (Builder $query, string $direction): Builder => self::orderByPlan($query, false, $direction)),
                IconColumn::make('checked_in_at')
                    ->label(__('registration.admin.checked_in'))
                    ->boolean()
                    ->state(fn (EventRegistration $r): bool => $r->checked_in_at !== null),
            ])
            ->filters([
                SelectFilter::make('arrival_day')
                    ->label(__('registration.admin.arrival_columns.filter_day'))
                    ->options(collect(EventDays::options($event))->pluck('label', 'value')->all()),
                SelectFilter::make('arrival_window')
                    ->label(__('registration.admin.arrival_columns.filter_window'))
                    ->options(collect(TimeWindow::ordered())->mapWithKeys(fn (TimeWindow $w): array => [$w->value => $w->label()])->all()),
                Filter::make('without_arrival')
                    ->label(__('registration.admin.arrival_columns.without_plan'))
                    ->toggle()
                    ->query(fn (Builder $query): Builder => $query->whereNull('arrival_day')),
            ])
            ->defaultSort('arrival_day')
            ->paginated([25, 50, 100]);
    }

    /**
     * Day first, then window in its defined order (keep in sync with
     * {@see TimeWindow::order()}; a test pins it); unknown values sort last.
     *
     * @param  Builder<EventRegistration>  $query
     * @return Builder<EventRegistration>
     */
    private static function orderByPlan(Builder $query, bool $arrival, string $direction): Builder
    {
        $direction = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return $arrival
            ? $query->orderByRaw('CASE WHEN arrival_day IS NULL THEN 1 ELSE 0 END')
                ->orderBy('arrival_day', $direction)
                ->orderByRaw("CASE arrival_window WHEN 'morning' THEN 0 WHEN 'afternoon' THEN 1 WHEN 'evening' THEN 2 WHEN 'night' THEN 3 ELSE 9 END {$direction}")
            : $query->orderByRaw('CASE WHEN departure_day IS NULL THEN 1 ELSE 0 END')
                ->orderBy('departure_day', $direction)
                ->orderByRaw("CASE departure_window WHEN 'morning' THEN 0 WHEN 'afternoon' THEN 1 WHEN 'evening' THEN 2 WHEN 'night' THEN 3 ELSE 9 END {$direction}");
    }
}
