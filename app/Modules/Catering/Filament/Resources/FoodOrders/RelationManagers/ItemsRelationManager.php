<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\RelationManagers;

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
use Illuminate\Support\HtmlString;
use LogicException;

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
            ]);
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
                e(number_format($row['totalCents'] / 100, 2)).' €',
            ))
            ->implode('');

        $grandTotal = number_format($split['grandTotalCents'] / 100, 2);

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
                            <td class="fi-ta-cell px-3 py-2 text-right font-bold">{$grandTotal} €</td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            HTML);
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
}
