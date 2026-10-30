<?php

namespace App\Modules\Registration\Filament\Widgets;

use App\Modules\Registration\Enums\TimeWindow;
use App\Modules\Registration\Filament\Widgets\Concerns\ForArrivalOverview;
use App\Modules\Registration\Support\EventDays;
use Carbon\CarbonImmutable;
use Filament\Widgets\ChartWidget;

/** Stacked bars: expected arrivals per day, split by window, plus an "Unbekannt" bar. */
class ArrivalsByDayChart extends ChartWidget
{
    use ForArrivalOverview;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('registration.admin.charts.arrivals_heading');
    }

    public function getDescription(): ?string
    {
        return __('registration.admin.charts.arrivals_desc');
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $f = $this->overview()->forecast;

        $days = [];
        foreach ($f->slots as $slot) {
            $days[$slot['day']] = EventDays::label(CarbonImmutable::parse($slot['day']));
        }
        $dayKeys = array_keys($days);
        $unknownLabel = __('registration.admin.charts.unknown');

        $shades = ['rgb(203, 213, 225)', 'rgb(148, 163, 184)', 'rgb(100, 116, 139)', 'rgb(51, 65, 85)'];
        $datasets = [];

        foreach (TimeWindow::ordered() as $window) {
            $data = array_map(function (string $day) use ($f, $window): int {
                foreach ($f->slots as $slot) {
                    if ($slot['day'] === $day && $slot['window'] === $window) {
                        return $slot['arrivals'];
                    }
                }

                return 0;
            }, $dayKeys);

            $datasets[] = [
                'label' => $window->label(),
                'data' => [...$data, 0],
                'backgroundColor' => $shades[$window->order()],
                'stack' => 'arrivals',
            ];
        }

        $datasets[] = [
            'label' => $unknownLabel,
            'data' => [...array_fill(0, count($dayKeys), 0), $f->unknown],
            'backgroundColor' => 'rgba(245, 158, 11, 0.25)',
            'borderColor' => 'rgb(245, 158, 11)',
            'borderWidth' => 1,
            'stack' => 'arrivals',
        ];

        return [
            'datasets' => $datasets,
            'labels' => [...array_values($days), $unknownLabel],
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => [
                'x' => ['stacked' => true],
                'y' => ['stacked' => true, 'beginAtZero' => true, 'ticks' => ['precision' => 0]],
            ],
            'plugins' => ['legend' => ['position' => 'bottom']],
        ];
    }
}
