<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Actions\CloseFoodOrder;
use App\Modules\Catering\Actions\OpenFoodOrder;
use App\Modules\Catering\Actions\RecomputeIncludedItems;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Support\GroupOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\HtmlString;

class EditFoodOrder extends EditRecord
{
    protected static string $resource = FoodOrderResource::class;

    /**
     * The menu lives with the restaurant (or, for rounds that predate restaurants, in the legacy
     * column that is no longer edited here), so it never enters the form state.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        unset($data['menu']);

        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $record->update($data);

        if ($record instanceof FoodOrder && $record->status !== FoodOrderStatus::Closed) {
            app(RecomputeIncludedItems::class)->handle($record);
        }

        return $record;
    }

    /**
     * Status badge plus a one-line summary (dishes, orders, sum, paid, open)
     * under the title, so the numbers are there before the form.
     */
    public function getSubheading(): string|Htmlable|null
    {
        /** @var FoodOrder $record */
        $record = $this->getRecord();
        $group = GroupOrder::for($record->refresh());

        return new HtmlString(view('filament.food-orders.summary', [
            'status' => $record->status,
            'dishes' => count($record->menuOptions()),
            'group' => $group,
        ])->render());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('group_order')
                ->label(__('catering.group_order.action'))
                ->icon(Heroicon::OutlinedClipboardDocumentList)
                ->color('gray')
                ->authorize('update')
                ->url(fn (FoodOrder $record): string => FoodOrderResource::getUrl('group-order', ['record' => $record])),
            Action::make('open')
                ->label(__('catering.admin.actions.open'))
                ->authorize('open')
                // Only offered from Draft, mirroring OpenFoodOrder's own
                // guard, so the button reflects reality instead of being a
                // dead click that always ends in a caught exception.
                ->visible(fn (FoodOrder $record) => $record->status === FoodOrderStatus::Draft)
                ->requiresConfirmation()
                ->action(function (FoodOrder $record): void {
                    try {
                        app(OpenFoodOrder::class)->handle($record);
                    } catch (CateringException $exception) {
                        Notification::make()
                            ->title(__($exception->translationKey))
                            ->danger()
                            ->send();

                        return;
                    }

                    // The action locks and saves a *fresh* instance
                    // internally (see OpenFoodOrder), so `$record` here is
                    // still the pre-transition object in memory. Refresh it
                    // before refreshFormData(['status']) re-fills the form,
                    // otherwise the header still shows the old status until
                    // a manual page reload.
                    $record->refresh();

                    Notification::make()
                        ->title(__('catering.admin.actions.opened'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            Action::make('close')
                ->label(__('catering.admin.actions.close'))
                ->authorize('close')
                // Only offered from Open, mirroring CloseFoodOrder's own
                // guard.
                ->visible(fn (FoodOrder $record) => $record->status === FoodOrderStatus::Open)
                ->requiresConfirmation()
                ->action(function (FoodOrder $record): void {
                    try {
                        app(CloseFoodOrder::class)->handle($record);
                    } catch (CateringException $exception) {
                        Notification::make()
                            ->title(__($exception->translationKey))
                            ->danger()
                            ->send();

                        return;
                    }

                    // See the `open` action above: the action mutates a
                    // fresh instance internally, so `$record` must be
                    // refreshed before refreshFormData(['status']).
                    $record->refresh();

                    Notification::make()
                        ->title(__('catering.admin.actions.closed'))
                        ->success()
                        ->send();

                    $this->refreshFormData(['status']);
                }),
            DeleteAction::make()
                ->authorize('update'),
        ];
    }
}
