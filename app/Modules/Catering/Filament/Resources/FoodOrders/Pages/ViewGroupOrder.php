<?php

namespace App\Modules\Catering\Filament\Resources\FoodOrders\Pages;

use App\Modules\Catering\Actions\ExportFoodOrderItemsCsv;
use App\Modules\Catering\Filament\Resources\FoodOrders\FoodOrderResource;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Support\GroupOrder;
use Filament\Actions\Action;
use Filament\Resources\Pages\Concerns\InteractsWithRecord;
use Filament\Resources\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Orga view of one round as a consolidated order: kitchen list for the
 * restaurant, person list for the cashier, copy text, print view and CSV.
 * Rendering only; the numbers come from {@see GroupOrder}.
 */
class ViewGroupOrder extends Page
{
    use InteractsWithRecord;

    protected static string $resource = FoodOrderResource::class;

    protected string $view = 'filament.pages.food-order-group-order';

    public function mount(int|string $record): void
    {
        $this->record = $this->resolveRecord($record);

        abort_unless(Gate::allows('update', $this->order()), 403);
    }

    /** @param array<string, mixed> $parameters */
    public static function canAccess(array $parameters = []): bool
    {
        $record = $parameters['record'] ?? null;

        return $record instanceof FoodOrder && Gate::allows('update', $record);
    }

    public function getTitle(): string|Htmlable
    {
        return __('catering.group_order.title', ['title' => $this->order()->title]);
    }

    public function getBreadcrumb(): string
    {
        return __('catering.group_order.breadcrumb');
    }

    public function order(): FoodOrder
    {
        $record = $this->getRecord();
        assert($record instanceof FoodOrder);

        return $record;
    }

    public function groupOrder(): GroupOrder
    {
        return GroupOrder::for($this->order()->refresh());
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('back')
                ->label(__('catering.group_order.back'))
                ->icon(Heroicon::OutlinedArrowLeft)
                ->color('gray')
                ->url(fn (): string => FoodOrderResource::getUrl('edit', ['record' => $this->order()])),
            Action::make('export')
                ->label(__('catering.admin.export_csv'))
                ->icon(Heroicon::OutlinedArrowDownTray)
                ->color('gray')
                ->authorize(fn (): bool => Gate::allows('export', $this->order()))
                ->action(fn (): StreamedResponse => $this->exportCsv()),
        ];
    }

    private function exportCsv(): StreamedResponse
    {
        Gate::authorize('export', $this->order());

        $csv = app(ExportFoodOrderItemsCsv::class)->handle($this->order());

        return response()->streamDownload(
            function () use ($csv): void {
                echo $csv;
            },
            Str::slug($this->order()->title).'.csv',
            ['Content-Type' => 'text/csv; charset=UTF-8'],
        );
    }
}
