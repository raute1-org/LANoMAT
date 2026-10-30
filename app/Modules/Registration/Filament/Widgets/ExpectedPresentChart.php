<?php

namespace App\Modules\Registration\Filament\Widgets;

use App\Modules\Registration\Filament\Widgets\Concerns\ForArrivalOverview;
use App\Modules\Registration\Support\ArrivalForecast;
use App\Modules\Registration\Support\EventDays;
use Filament\Widgets\ChartWidget;

/** Expected people on site per slot (arrived minus departed). Unknown arrivals are not included. */
class ExpectedPresentChart extends ChartWidget
{
    use ForArrivalOverview;

    protected ?string $pollingInterval = null;

    protected int|string|array $columnSpan = 'full';

    protected ?string $maxHeight = '320px';

    public function getHeading(): ?string
    {
        return __('registration.admin.charts.present_heading');
    }

    public function getDescription(): ?string
    {
        $f = $this->overview()->forecast;

        $peak = $f->peak === null
            ? __('registration.admin.charts.no_peak')
            : __('registration.admin.charts.peak', [
                'count' => $f->peak['count'],
                'slot' => ArrivalForecast::slotLabel($f->slots[$f->peak['index']]),
            ]);

        $note = $f->unknown > 0 ? ' '.__('registration.admin.charts.unknown_note', ['count' => $f->unknown]) : '';

        return $peak.'. '.__('registration.admin.charts.present_desc').$note;
    }

    protected function getType(): string
    {
        return 'bar';
    }

    /** @return array<string, mixed> */
    protected function getData(): array
    {
        $f = $this->overview()->forecast;
        $peakIndex = $f->peak['index'] ?? null;

        return [
            'datasets' => [[
                'label' => __('registration.admin.charts.present'),
                'data' => array_column($f->slots, 'present'),
                'backgroundColor' => array_map(
                    fn (array $slot): string => $slot['index'] === $peakIndex ? 'rgb(245, 158, 11)' : 'rgb(100, 116, 139)',
                    $f->slots,
                ),
            ]],
            'labels' => array_map(
                fn (array $slot): string => EventDays::weekday($slot['startsAt']).' '.$slot['window']->shortLabel(),
                $f->slots,
            ),
        ];
    }

    /** @return array<string, mixed> */
    protected function getOptions(): array
    {
        return [
            'scales' => ['y' => ['beginAtZero' => true, 'ticks' => ['precision' => 0]]],
            'plugins' => ['legend' => ['display' => false]],
        ];
    }
}
