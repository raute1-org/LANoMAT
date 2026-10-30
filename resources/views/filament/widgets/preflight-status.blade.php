@php
    // Filament-native only: the admin panel does not load the app's Tailwind
    // CSS, so this view uses x-filament components and fi-* classes only.
    $badgeColor = [
        'ok' => 'success',
        'warn' => 'warning',
        'down' => 'danger',
        'skipped' => 'gray',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Preflight-Ampel" divided>
        <x-slot name="afterHeader">
            <x-filament::button color="primary" wire:click="refresh">
                Neu prüfen
            </x-filament::button>
        </x-slot>

        @foreach ($this->results() as $result)
            <div>
                <div>
                    <x-filament::badge :color="$badgeColor[$result['status']] ?? 'gray'">
                        {{ __('preflight.status.'.$result['status']) }}
                    </x-filament::badge>

                    <span>{{ $result['label'] }}</span>
                </div>

                @if (filled($result['message']))
                    <p class="fi-section-header-description">
                        {{ $result['message'] }}
                    </p>
                @endif
            </div>
        @endforeach
    </x-filament::section>
</x-filament-widgets::widget>
