@php
    $dotColorClass = [
        'ok' => 'bg-ok',
        'warn' => 'bg-warn',
        'down' => 'bg-down',
        'skipped' => 'bg-muted-foreground',
    ];
@endphp

<x-filament-widgets::widget>
    <x-filament::section heading="Preflight-Ampel">
        <x-slot name="afterHeader">
            <x-filament::button color="primary" wire:click="refresh">
                Neu prüfen
            </x-filament::button>
        </x-slot>

        <ul class="divide-y divide-border">
            @foreach ($this->results() as $result)
                <li class="flex items-center gap-3 py-2">
                    <span
                        class="inline-flex size-2 shrink-0 rounded-full {{ $dotColorClass[$result['status']] ?? 'bg-muted-foreground' }}"
                        aria-hidden="true"
                    ></span>

                    <span class="flex-1 text-sm text-foreground">
                        {{ $result['label'] }}
                    </span>

                    <span class="font-mono text-xs text-muted-foreground">
                        {{ $result['message'] }}
                    </span>
                </li>
            @endforeach
        </ul>
    </x-filament::section>
</x-filament-widgets::widget>
