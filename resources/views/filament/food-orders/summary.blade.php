@php
    $euro = fn (int $cents): string => \App\Support\Money\Euro::format($cents);
    $stat = 'display: inline-flex; gap: 0.35rem; align-items: baseline; margin-right: 1.25rem;';
    $num = 'font-weight: 600; font-variant-numeric: tabular-nums; font-family: ui-monospace, monospace;';
@endphp
<div style="display: flex; flex-wrap: wrap; align-items: center; gap: 0.25rem 0.5rem; margin-top: 0.25rem;">
    <x-filament::badge :color="$status->color()" style="margin-right: 0.75rem;">{{ $status->label() }}</x-filament::badge>
    <span style="{{ $stat }}"><span style="{{ $num }}">{{ $dishes }}</span> {{ __('catering.summary.dishes') }}</span>
    <span style="{{ $stat }}"><span style="{{ $num }}">{{ $group->positions }}</span> {{ __('catering.summary.orders') }}</span>
    <span style="{{ $stat }}"><span style="{{ $num }}">{{ $euro($group->totalCents) }}</span> {{ __('catering.summary.total') }}</span>
    <span style="{{ $stat }}"><span style="{{ $num }}">{{ $euro($group->paidCents) }}</span> {{ __('catering.summary.paid') }}</span>
    <span style="{{ $stat }}"><span style="{{ $num }}">{{ $euro($group->dueCents) }}</span> {{ __('catering.summary.due') }}</span>
</div>
