<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers;

use App\Modules\Catering\Actions\ExportFoodOrderItemsCsv;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Support\CostSplit;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\Summarizers\Sum;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
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

    public function table(Table $table): Table
    {
        return $table
            ->modelLabel(__('catering.admin.item_model_label'))
            ->pluralModelLabel(__('catering.admin.item_plural_model_label'))
            ->emptyStateHeading(__('catering.admin.items_empty'))
            ->columns([
                TextColumn::make('user.name')
                    ->label(__('catering.admin.participant')),
                TextColumn::make('selection.option_key')
                    ->label(__('catering.admin.option')),
                TextColumn::make('price_cents')
                    ->label(__('catering.admin.price'))
                    ->money('EUR', divideBy: 100)
                    ->summarize(Sum::make()->money('EUR', divideBy: 100)),
                IconColumn::make('paid_at')
                    ->label(__('catering.admin.paid'))
                    ->boolean()
                    ->state(fn (FoodOrderItem $record) => $record->paid_at !== null),
            ])
            ->recordActions([
                Action::make('toggle_paid')
                    ->label(__('catering.admin.toggle_paid'))
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
                Action::make('export')
                    ->label(__('catering.admin.export_csv'))
                    ->authorize(fn () => $this->canExport())
                    ->action(fn (): StreamedResponse => $this->exportCsv()),
            ]);
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
                '<tr><td class="fi-ta-cell px-3 py-2">%s</td><td class="fi-ta-cell px-3 py-2 text-right">%s</td></tr>',
                e($row['name']),
                e($this->formatEuro($row['totalCents'])),
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

    private function totalsGrandLabel(): string
    {
        return e(__('catering.admin.grand_total'));
    }

    private function optionCountsLabel(): string
    {
        return e(__('catering.admin.option_counts'));
    }
}
