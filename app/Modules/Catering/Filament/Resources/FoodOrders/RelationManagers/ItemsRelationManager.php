<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers;

use App\Modules\Catering\Actions\ExportFoodOrderItemsCsv;
use App\Modules\Catering\Actions\MarkUserItemsPaid;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\CostSplit;
use App\Modules\Catering\Support\GroupOrder;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Str;
use LogicException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('catering.admin.items_title');
    }

    public const TAB_DISH = 'dish';

    public const TAB_PERSON = 'person';

    /**
     * Two views of the same rows: grouped by dish (what to cook/order, with
     * quantity and sum) and grouped by person (who owes what).
     *
     * @return array<string|int, Tab>
     */
    public function getTabs(): array
    {
        return [
            self::TAB_DISH => Tab::make(__('catering.admin.tabs.dish'))->icon(Heroicon::OutlinedCake),
            self::TAB_PERSON => Tab::make(__('catering.admin.tabs.person'))->icon(Heroicon::OutlinedUser),
        ];
    }

    public function table(Table $table): Table
    {
        $byPerson = $this->activeTab === self::TAB_PERSON;

        return $table
            ->modelLabel(__('catering.admin.item_model_label'))
            ->pluralModelLabel(__('catering.admin.item_plural_model_label'))
            ->emptyStateHeading(__('catering.admin.items_empty'))
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('user')
                ->select('food_order_items.*')
                ->selectRaw("food_order_items.selection->>'option_key' as dish_key")
                ->selectRaw('(select name from users where users.id = food_order_items.user_id) as person_name'))
            ->groups([
                Group::make('dish_key')
                    ->label(__('catering.admin.option'))
                    ->getTitleFromRecordUsing(fn (FoodOrderItem $record): string => $this->dishName($record))
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('dish_key', $direction === 'desc' ? 'desc' : 'asc')),
                Group::make('person_name')
                    ->label(__('catering.admin.participant'))
                    ->getTitleFromRecordUsing(fn (FoodOrderItem $record): string => $record->user->name ?? '')
                    ->orderQueryUsing(fn (Builder $query, string $direction) => $query->orderBy('person_name', $direction === 'desc' ? 'desc' : 'asc')),
            ])
            ->defaultGroup($byPerson ? 'person_name' : 'dish_key')
            ->groupingSettingsHidden()
            ->defaultSort('id')
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('catering.admin.participant'))
                    ->visible(! $byPerson)
                    ->searchable(),
                TextColumn::make('selection.option_key')
                    ->label(__('catering.admin.option'))
                    ->state(fn (FoodOrderItem $record): string => $this->dishName($record))
                    ->visible($byPerson)
                    ->searchable(query: fn (Builder $query, string $search): Builder => $this->searchDish($query, $search)),
                TextColumn::make('selection.note')
                    ->label(__('catering.admin.note'))
                    ->placeholder('–')
                    ->wrap(),
                TextColumn::make('price_cents')
                    ->label(__('catering.admin.price'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->summarize(Sum::make()->money('EUR', divideBy: 100)->label(__('catering.admin.grand_total'))),
                IconColumn::make('paid_at')
                    ->label(__('catering.admin.paid'))
                    ->boolean()
                    ->state(fn (FoodOrderItem $record) => $record->paid_at !== null || $record->included),
                IconColumn::make('included')
                    ->label(__('catering.admin.included'))
                    ->boolean(),
            ])
            ->filters([
                TernaryFilter::make('paid')
                    ->label(__('catering.admin.paid'))
                    ->placeholder(__('catering.admin.filter_all'))
                    ->trueLabel(__('catering.admin.filter_paid'))
                    ->falseLabel(__('catering.admin.filter_open'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->where(fn (Builder $q) => $q->whereNotNull('paid_at')->orWhere('included', true)),
                        false: fn (Builder $query): Builder => $query->whereNull('paid_at')->where('included', false),
                        blank: fn (Builder $query): Builder => $query,
                    ),
            ])
            ->recordActions([
                Action::make('mark_user_paid')
                    ->label(fn (FoodOrderItem $record): string => __('catering.admin.mark_user_paid', ['name' => $record->user->name ?? '']))
                    ->icon(Heroicon::OutlinedBanknotes)
                    ->authorize(fn () => auth()->user()?->isOrga() ?? false)
                    ->visible(fn (FoodOrderItem $record): bool => ! $record->included && $record->paid_at === null)
                    ->requiresConfirmation()
                    ->action(function (FoodOrderItem $record): void {
                        $order = $this->getOwnerRecord();

                        if ($order instanceof FoodOrder) {
                            app(MarkUserItemsPaid::class)->handle($order, $record->user_id);
                        }
                    }),
                Action::make('toggle_paid')
                    ->label(__('catering.admin.toggle_paid'))
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->color('gray')
                    ->authorize(fn () => auth()->user()?->isOrga() ?? false)
                    ->action(function (FoodOrderItem $record): void {
                        $record->paid_at = $record->paid_at === null ? Carbon::now() : null;
                        $record->save();
                    }),
            ])
            ->headerActions([
                Action::make('totals')
                    ->label(__('catering.admin.totals'))
                    ->modalHeading(__('catering.admin.totals'))
                    ->modalContent(fn (): HtmlString => $this->renderTotals())
                    ->modalSubmitAction(false)
                    ->modalCancelActionLabel(__('catering.admin.close_modal')),
                Action::make('group_order')
                    ->label(__('catering.group_order.action'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->color('gray')
                    ->authorize(fn () => $this->canExport())
                    ->url(fn (): string => FoodOrderResource::getUrl('group-order', ['record' => $this->getOwnerRecord()])),
                Action::make('export')
                    ->label(__('catering.admin.export_csv'))
                    ->icon(Heroicon::OutlinedArrowDownTray)
                    ->color('gray')
                    ->authorize(fn () => $this->canExport())
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ]);
    }

    private function dishName(FoodOrderItem $record): string
    {
        $key = (string) ($record->selection['option_key'] ?? '');
        $order = $this->getOwnerRecord();

        return $order instanceof FoodOrder ? (GroupOrder::dishesOf($order)[$key] ?? $key) : $key;
    }

    /**
     * Search by dish name: the stored key is not what the orga sees, so map
     * the matching menu names back to keys.
     *
     * @param  Builder<FoodOrderItem>  $query
     * @return Builder<FoodOrderItem>
     */
    private function searchDish(Builder $query, string $search): Builder
    {
        $order = $this->getOwnerRecord();
        $needle = mb_strtolower($search);
        $keys = [];

        if ($order instanceof FoodOrder) {
            foreach (GroupOrder::dishesOf($order) as $key => $name) {
                if (str_contains(mb_strtolower($name), $needle)) {
                    $keys[] = $key;
                }
            }
        }

        return $query->orWhereIn(DB::raw("food_order_items.selection->>'option_key'"), $keys);
    }

    private function canExport(): bool
    {
        $order = $this->getOwnerRecord();

        return $order instanceof FoodOrder && Gate::allows('export', $order);
    }

    private function exportCsv(): StreamedResponse
    {
        $order = $this->getOwnerRecord();

        if (! $order instanceof FoodOrder) {
            throw new LogicException('ItemsRelationManager must be attached to a FoodOrder record.');
        }

        Gate::authorize('export', $order);

        $csv = app(ExportFoodOrderItemsCsv::class)->handle($order);

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            Str::slug($order->title).'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }

    private function renderTotals(): HtmlString
    {
        $order = $this->getOwnerRecord();

        if (! $order instanceof FoodOrder) {
            throw new LogicException('ItemsRelationManager must be attached to a FoodOrder record.');
        }

        $split = CostSplit::for($order);

        $rows = collect($split['perUser'])
            ->map(fn (array $row) => sprintf(
                '<tr><td class="fi-ta-cell px-3 py-2">%s</td><td class="fi-ta-cell px-3 py-2 text-right">%s</td><td class="fi-ta-cell px-3 py-2 text-right">%s</td><td class="fi-ta-cell px-3 py-2 text-right">%s</td></tr>',
                e($row['name']),
                e($this->formatEuro($row['totalCents'])),
                e($this->formatEuro($row['includedCents'])),
                e($this->formatEuro($row['dueCents'])),
            ))
            ->implode('');

        $optionRows = collect($split['byOption'])
            ->map(fn (array $row) => sprintf(
                '<tr><td class="fi-ta-cell px-3 py-2">%s</td><td class="fi-ta-cell px-3 py-2 text-right">%s</td></tr>',
                e(__('catering.admin.option_count_line', ['count' => $row['count'], 'name' => $row['name']])),
                e($this->formatEuro($row['totalCents'])),
            ))
            ->implode('');

        $grandTotal = $this->formatEuro($split['grandTotalCents']);

        return new HtmlString(<<<HTML
            <div class="fi-ta-ctn overflow-x-auto">
                <table class="fi-ta-table w-full text-sm">
                    <thead>
                        <tr>
                            <th class="fi-ta-header-cell px-3 py-2 text-left">{$this->totalsHeaderLabel()}</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-right">{$this->totalsAmountLabel()}</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-right">{$this->includedLabel()}</th>
                            <th class="fi-ta-header-cell px-3 py-2 text-right">{$this->dueLabel()}</th>
                        </tr>
                    </thead>
                    <tbody>
                        {$rows}
                    </tbody>
                    <tfoot>
                        <tr>
                            <td class="fi-ta-cell px-3 py-2 font-bold">{$this->totalsGrandLabel()}</td>
                            <td class="fi-ta-cell px-3 py-2 text-right font-bold">{$grandTotal}</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <h3 class="mt-6 text-sm font-semibold">{$this->optionCountsLabel()}</h3>
            <div class="fi-ta-ctn mt-2 overflow-x-auto">
                <table class="fi-ta-table w-full text-sm">
                    <tbody>
                        {$optionRows}
                    </tbody>
                </table>
            </div>
            HTML);
    }

    /**
     * German format: decimal comma, point as thousands separator, e.g. "1.000,00 €".
     */
    private function formatEuro(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.').' €';
    }

    private function totalsHeaderLabel(): string
    {
        return e(__('catering.admin.participant'));
    }

    private function totalsAmountLabel(): string
    {
        return e(__('catering.admin.price'));
    }

    private function includedLabel(): string
    {
        return e(__('catering.admin.included'));
    }

    private function dueLabel(): string
    {
        return e(__('catering.admin.due'));
    }

    private function totalsGrandLabel(): string
    {
        return e(__('catering.admin.grand_total'));
    }

    private function optionCountsLabel(): string
    {
        return e(__('catering.admin.option_counts'));
    }
}
