@php
    $group = $this->groupOrder();
    $euro = fn (int $cents): string => \App\Support\Money\Euro::format($cents);
@endphp

<x-filament-panels::page>
    <style>
        .go-grid { display: grid; gap: 1.5rem; grid-template-columns: minmax(0, 1fr); }
        @media (min-width: 1024px) { .go-grid { grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); } }
        .go-table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
        .go-table th { text-align: left; font-weight: 600; padding: 0.375rem 0.5rem; border-bottom: 1px solid rgba(128, 128, 128, 0.35); }
        .go-table td { padding: 0.375rem 0.5rem; border-bottom: 1px solid rgba(128, 128, 128, 0.15); vertical-align: top; }
        .go-num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; font-family: ui-monospace, monospace; }
        .go-cat td { font-weight: 600; background: rgba(128, 128, 128, 0.08); }
        .go-note { opacity: 0.7; font-size: 0.8rem; }
        .go-text { width: 100%; min-height: 12rem; font-family: ui-monospace, monospace; font-size: 0.8rem; }
        .go-bar { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; margin-bottom: 0.75rem; }
        .go-sum { display: flex; flex-wrap: wrap; gap: 1.5rem; font-size: 0.875rem; }
        .go-print-title { display: none; }
        @media print {
            .fi-sidebar, .fi-topbar, .fi-header, .fi-breadcrumbs, .fi-sidebar-close-overlay, .fi-no-print, [data-shell="admin-marker"] { display: none !important; }
            .fi-main { margin: 0 !important; padding: 0 !important; max-width: none !important; }
            .go-print-title { display: block; font-weight: 700; font-size: 1.25rem; margin-bottom: 0.5rem; }
            .go-grid { display: block; }
            .go-persons { page-break-before: always; }
        }
    </style>

    <div class="go-print-title">{{ $group->title }}</div>

    <div class="go-sum">
        <span>{{ __('catering.group_order.positions') }}: <strong class="go-num">{{ $group->positions }}</strong></span>
        <span>{{ __('catering.group_order.total') }}: <strong class="go-num">{{ $euro($group->totalCents) }}</strong></span>
        <span>{{ __('catering.group_order.due') }}: <strong class="go-num">{{ $euro($group->dueCents) }}</strong></span>
        <span>{{ __('catering.group_order.paid') }}: <strong class="go-num">{{ $euro($group->paidCents) }}</strong></span>
    </div>

    <div class="go-grid">
        <x-filament::section :heading="__('catering.group_order.kitchen')" :description="__('catering.group_order.kitchen_help')">
            @if ($group->categories === [])
                <p>{{ __('catering.group_order.empty') }}</p>
            @else
                <table class="go-table">
                    <thead>
                        <tr>
                            <th>{{ __('catering.group_order.dish') }}</th>
                            <th class="go-num">{{ __('catering.group_order.unit') }}</th>
                            <th class="go-num">{{ __('catering.group_order.sum') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group->categories as $category)
                            <tr class="go-cat"><td colspan="3">{{ $category['label'] }}</td></tr>
                            @foreach ($category['lines'] as $line)
                                <tr>
                                    <td>
                                        {{ $group->kitchenLine($line) }}
                                    </td>
                                    <td class="go-num">{{ $euro($line['unitCents']) }}</td>
                                    <td class="go-num">{{ $euro($line['totalCents']) }}</td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                    <tfoot>
                        <tr>
                            <td><strong>{{ __('catering.group_order.total') }} ({{ $group->positions }})</strong></td>
                            <td></td>
                            <td class="go-num"><strong>{{ $euro($group->totalCents) }}</strong></td>
                        </tr>
                    </tfoot>
                </table>
            @endif

            <div class="fi-no-print" style="margin-top: 1rem;" x-data="{
                copied: false,
                async copy() {
                    const text = this.$refs.text.value;
                    try { await navigator.clipboard.writeText(text); } catch (e) { this.$refs.text.select(); document.execCommand('copy'); }
                    this.copied = true;
                    setTimeout(() => this.copied = false, 2000);
                },
            }">
                <div class="go-bar">
                    <x-filament::button type="button" size="sm" icon="heroicon-o-clipboard-document" x-on:click="copy()">
                        {{ __('catering.group_order.copy') }}
                    </x-filament::button>
                    <x-filament::button type="button" size="sm" color="gray" icon="heroicon-o-printer" x-on:click="window.print()">
                        {{ __('catering.group_order.print') }}
                    </x-filament::button>
                    <span x-show="copied" x-cloak role="status">{{ __('catering.group_order.copied') }}</span>
                </div>
                <textarea x-ref="text" class="go-text" readonly aria-label="{{ __('catering.group_order.text_label') }}">{{ $group->text() }}</textarea>
            </div>
        </x-filament::section>

        <x-filament::section class="go-persons" :heading="__('catering.group_order.persons')" :description="__('catering.group_order.persons_help')">
            @if ($group->persons === [])
                <p>{{ __('catering.group_order.empty') }}</p>
            @else
                <table class="go-table">
                    <thead>
                        <tr>
                            <th>{{ __('catering.admin.participant') }}</th>
                            <th class="go-num">{{ __('catering.group_order.sum') }}</th>
                            <th>{{ __('catering.group_order.state') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($group->persons as $person)
                            <tr>
                                <td>
                                    {{ $person['name'] }}
                                    <div class="go-note">{{ implode(', ', $person['dishes']) }}</div>
                                </td>
                                <td class="go-num">
                                    {{ $euro($person['totalCents']) }}
                                    @if ($person['includedCents'] > 0)
                                        <div class="go-note">{{ __('catering.group_order.included_part', ['amount' => $euro($person['includedCents'])]) }}</div>
                                    @endif
                                </td>
                                <td>
                                    @if ($person['state'] === 'open')
                                        {{ __('catering.group_order.state_open', ['amount' => $euro($person['dueCents'])]) }}
                                    @elseif ($person['state'] === 'paid')
                                        {{ __('catering.group_order.state_paid') }}
                                    @else
                                        {{ __('catering.group_order.state_included') }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @endif
        </x-filament::section>
    </div>
</x-filament-panels::page>
