<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Tables;

use App\Modules\Catering\Actions\CloseFoodOrder;
use App\Modules\Catering\Actions\OpenFoodOrder;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FoodOrdersTable
{
    public static function configure(Table $table): Table
    {
        $timezone = (string) config('lanomat.timezone', 'Europe/Berlin');

        return $table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query
                ->with('event')
                ->withCount('items')
                ->withSum('items as total_cents', 'price_cents')
                ->withSum(['items as due_cents' => fn (Builder $items) => $items->where('included', false)->whereNull('paid_at')], 'price_cents'))
            ->columns([
                TextColumn::make('title')
                    ->label(__('catering.fields.title'))
                    ->weight('semibold')
                    ->searchable()
                    ->description(fn (FoodOrder $record): string => $record->event->name ?? ''),
                TextColumn::make('status')
                    ->label(__('catering.fields.status'))
                    ->badge()
                    ->color(fn (FoodOrderStatus $state): string => $state->color())
                    ->formatStateUsing(fn (FoodOrderStatus $state) => $state->label())
                    ->sortable(),
                TextColumn::make('opens_at')
                    ->label(__('catering.list.window'))
                    ->state(fn (FoodOrder $record): string => self::window($record, $timezone))
                    ->sortable(),
                TextColumn::make('items_count')
                    ->label(__('catering.list.orders'))
                    ->numeric()
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->sortable(),
                TextColumn::make('total_cents')
                    ->label(__('catering.list.total'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->placeholder('0,00 €')
                    ->sortable(),
                TextColumn::make('due_cents')
                    ->label(__('catering.list.due'))
                    ->money('EUR', divideBy: 100)
                    ->alignEnd()
                    ->fontFamily('mono')
                    ->placeholder('0,00 €')
                    ->color(fn (mixed $state): ?string => (int) $state > 0 ? 'warning' : null)
                    ->sortable(),
            ])
            ->defaultSort('created_at', 'desc')
            ->emptyStateHeading(__('catering.list.empty'))
            ->filters([
                SelectFilter::make('status')
                    ->label(__('catering.fields.status'))
                    ->multiple()
                    ->options(collect(FoodOrderStatus::cases())->mapWithKeys(fn (FoodOrderStatus $s): array => [$s->value => $s->label()])->all())
                    ->default([FoodOrderStatus::Draft->value, FoodOrderStatus::Open->value]),
                SelectFilter::make('event_id')
                    ->label(__('catering.fields.event'))
                    ->relationship('event', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                Action::make('group_order')
                    ->label(__('catering.group_order.action'))
                    ->icon(Heroicon::OutlinedClipboardDocumentList)
                    ->authorize('update')
                    ->url(fn (FoodOrder $record): string => FoodOrderResource::getUrl('group-order', ['record' => $record])),
                ActionGroup::make([
                    Action::make('open')
                        ->label(__('catering.admin.actions.open'))
                        ->icon(Heroicon::OutlinedPlay)
                        ->authorize('open')
                        ->visible(fn (FoodOrder $record): bool => $record->status === FoodOrderStatus::Draft)
                        ->requiresConfirmation()
                        ->action(fn (FoodOrder $record) => self::transition($record, OpenFoodOrder::class, 'catering.admin.actions.opened')),
                    Action::make('close')
                        ->label(__('catering.admin.actions.close'))
                        ->icon(Heroicon::OutlinedStop)
                        ->authorize('close')
                        ->visible(fn (FoodOrder $record): bool => $record->status === FoodOrderStatus::Open)
                        ->requiresConfirmation()
                        ->action(fn (FoodOrder $record) => self::transition($record, CloseFoodOrder::class, 'catering.admin.actions.closed')),
                    DeleteAction::make()
                        ->authorize('update'),
                ]),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    /** "21.11. 18:00 bis 22.11. 02:00" in the display timezone, "Offen" when unset. */
    private static function window(FoodOrder $order, string $timezone): string
    {
        $format = fn ($at): string => $at->copy()->timezone($timezone)->format('d.m. H:i');

        return match (true) {
            $order->opens_at !== null && $order->closes_at !== null => $format($order->opens_at).' – '.$format($order->closes_at),
            $order->opens_at !== null => __('catering.list.from', ['at' => $format($order->opens_at)]),
            $order->closes_at !== null => __('catering.list.until', ['at' => $format($order->closes_at)]),
            default => __('catering.list.no_window'),
        };
    }

    /**
     * @param  class-string<OpenFoodOrder|CloseFoodOrder>  $action
     */
    private static function transition(FoodOrder $record, string $action, string $successKey): void
    {
        try {
            app($action)->handle($record);
        } catch (CateringException $exception) {
            Notification::make()->title(__($exception->translationKey))->danger()->send();

            return;
        }

        Notification::make()->title(__($successKey))->success()->send();
    }
}
