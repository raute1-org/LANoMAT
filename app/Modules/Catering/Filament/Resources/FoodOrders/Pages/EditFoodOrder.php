<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Actions\CloseFoodOrder;
use App\Modules\Catering\Actions\OpenFoodOrder;
use App\Modules\Catering\Domain\MenuOption;
use App\Modules\Catering\Enums\FoodOrderStatus;
use App\Modules\Catering\Exceptions\CateringException;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;

class EditFoodOrder extends EditRecord
{
    protected static string $resource = FoodOrderResource::class;

    /**
     * The `menu` cast reads back as `list<MenuOption>` (typed value objects,
     * not plain arrays — see MenuCast), but Filament's Repeater fills/writes
     * its state via `data_set()` on plain associative arrays. Converting at
     * this Filament-only boundary keeps MenuCast/MenuOption fully typed
     * (no weakening) while still letting the Repeater round-trip the data.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function mutateFormDataBeforeFill(array $data): array
    {
        if (isset($data['menu']) && is_array($data['menu'])) {
            $data['menu'] = collect($data['menu'])
                ->map(fn (mixed $option): array => $option instanceof MenuOption ? $option->toArray() : $option)
                ->all();
        }

        return $data;
    }

    /**
     * `menu` is deliberately not in FoodOrder::$fillable (see the model's
     * comment: it must never be reachable via uncontrolled raw mass
     * assignment, e.g. a future public endpoint). The admin Filament layer
     * is already gated by FoodOrderPolicy::update, so it is the one place
     * allowed to set it explicitly, going through MenuCast exactly as any
     * other write would.
     *
     * @param  array<string, mixed>  $data
     */
    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        $menu = $data['menu'] ?? null;
        unset($data['menu']);

        $record->update($data);

        if ($menu !== null && $record instanceof FoodOrder) {
            $record->menu = $menu;
            $record->save();
        }

        return $record;
    }

    protected function getHeaderActions(): array
    {
        return [
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
