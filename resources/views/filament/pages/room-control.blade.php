@php
    /** @var \App\Modules\RoomAudio\Support\ReceiverState $state */
    $unreachable = ! $state->connected;
    $standby = $state->connected && ! $state->power;
    $locked = $unreachable || $standby;
    $volume = $state->volume;
@endphp

<x-filament-panels::page>
    <style>
        .rc-wrap { display: grid; gap: 1.5rem; max-width: 56rem; }
        .rc-status { display: flex; align-items: flex-start; gap: .75rem; }
        .rc-dot { width: .625rem; height: .625rem; border-radius: 9999px; margin-top: .4rem; flex: none; background: rgb(var(--gray-400, 156 163 175)); background: var(--gray-400); }
        .rc-dot[data-on="true"] { background: var(--success-600); }
        .rc-dot[data-error="true"] { background: var(--danger-600); }
        .rc-status-title { font-weight: 600; font-size: 1.125rem; }
        .rc-hint { color: var(--gray-500); font-size: .875rem; }
        .rc-volume-row { display: flex; align-items: center; gap: 1rem; flex-wrap: wrap; }
        .rc-volume { font-family: ui-monospace, 'JetBrains Mono', monospace; font-size: 3.5rem; line-height: 1; font-weight: 600; min-width: 5ch; color: var(--primary-600); font-variant-numeric: tabular-nums; }
        .dark .rc-volume { color: var(--primary-400); }
        .rc-volume[data-muted="true"] { color: var(--gray-500); text-decoration: line-through; }
        .rc-range { width: 100%; accent-color: var(--primary-600); min-height: 2rem; }
        .rc-range:focus-visible, .rc-field:focus-visible { outline: 2px solid var(--primary-600); outline-offset: 2px; }
        .rc-range:disabled { opacity: .5; }
        .rc-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
        @media (min-width: 40rem) { .rc-grid { grid-template-columns: 1fr 1fr; } }
        .rc-label { display: block; font-weight: 500; font-size: .875rem; margin-bottom: .375rem; }
        .rc-field { width: 100%; min-height: 2.5rem; padding: .25rem .75rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
        .dark .rc-field { border-color: var(--gray-600); }
        .rc-field option { color: #000; }
        .rc-field:disabled { opacity: .5; cursor: not-allowed; }
        .rc-ceiling { display: flex; gap: .5rem; align-items: center; }
        .rc-ceiling .rc-field { max-width: 7rem; font-family: ui-monospace, 'JetBrains Mono', monospace; }
        .rc-actions { display: flex; gap: .5rem; flex-wrap: wrap; align-items: center; }
        .rc-busy { color: var(--gray-500); font-size: .875rem; }
        @media (prefers-reduced-motion: reduce) { .rc-wrap * { transition: none !important; animation: none !important; } }
    </style>

    <div class="rc-wrap" wire:poll.5s>
        <x-filament::section>
            <div class="rc-status" role="status" aria-live="polite">
                <span class="rc-dot" data-on="{{ $locked ? 'false' : 'true' }}" data-error="{{ $unreachable ? 'true' : 'false' }}" aria-hidden="true"></span>
                <div>
                    @if ($unreachable)
                        <p class="rc-status-title">{{ __('room_audio.state.unreachable') }}</p>
                        <p class="rc-hint">{{ __('room_audio.state.unreachable_hint') }}</p>
                    @elseif ($standby)
                        <p class="rc-status-title">{{ __('room_audio.state.standby') }}</p>
                        <p class="rc-hint">{{ __('room_audio.state.standby_hint') }}</p>
                    @else
                        <p class="rc-status-title">{{ __('room_audio.state.on') }}</p>
                    @endif
                </div>
                <div style="margin-left: auto;" class="rc-actions">
                    <span class="rc-busy" wire:loading.delay.longer>{{ __('room_audio.state.loading') }}</span>
                    <x-filament::button
                        wire:click="togglePower"
                        :disabled="$unreachable"
                        :color="$standby ? 'primary' : 'gray'"
                    >
                        {{ $state->power ? __('room_audio.power_off') : __('room_audio.power_on') }}
                    </x-filament::button>
                </div>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('room_audio.volume')">
            <div class="rc-volume-row">
                <span class="rc-volume" data-muted="{{ $state->mute ? 'true' : 'false' }}">{{ $volume === null ? '–' : rtrim(rtrim(number_format($volume, 1, ',', ''), '0'), ',') }}</span>
                @if ($state->mute && ! $locked)
                    <span class="rc-hint">{{ __('room_audio.muted') }}</span>
                @endif
                <div class="rc-actions" style="margin-left: auto;">
                    <x-filament::button color="gray" wire:click="volumeStep(-1)" :disabled="$locked" :aria-label="__('room_audio.volume_down')">&minus;</x-filament::button>
                    <x-filament::button color="gray" wire:click="volumeStep(1)" :disabled="$locked" :aria-label="__('room_audio.volume_up')">+</x-filament::button>
                    <x-filament::button color="gray" wire:click="toggleMute" :disabled="$locked">
                        {{ $state->mute ? __('room_audio.unmute') : __('room_audio.mute') }}
                    </x-filament::button>
                </div>
            </div>
            <div style="margin-top: 1rem;">
                <input
                    type="range"
                    class="rc-range"
                    min="0"
                    max="{{ min($ceiling, (int) $state->volumeMax) }}"
                    step="0.5"
                    value="{{ $volume ?? 0 }}"
                    aria-label="{{ __('room_audio.volume_slider') }}"
                    x-on:change="$wire.setVolume(parseFloat($event.target.value))"
                    @disabled($locked)
                >
            </div>
        </x-filament::section>

        <div class="rc-grid">
            <x-filament::section :heading="__('room_audio.source')">
                <select class="rc-field" aria-label="{{ __('room_audio.source') }}" x-on:change="$wire.setSource($event.target.value)" @disabled($locked || $state->sources === [])>
                    @forelse ($state->sources as $source)
                        <option value="{{ $source['code'] }}" @selected($source['code'] === $state->source)>{{ $source['name'] }}</option>
                    @empty
                        <option>{{ __('room_audio.no_options') }}</option>
                    @endforelse
                </select>
            </x-filament::section>

            <x-filament::section :heading="__('room_audio.mode')">
                <select class="rc-field" aria-label="{{ __('room_audio.mode') }}" x-on:change="$wire.setMode($event.target.value)" @disabled($locked || $state->modes === [])>
                    @forelse ($state->modes as $mode)
                        <option value="{{ $mode['key'] }}" @selected($mode['key'] === $state->mode)>{{ $mode['name'] }}</option>
                    @empty
                        <option>{{ __('room_audio.no_options') }}</option>
                    @endforelse
                </select>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('room_audio.ceiling')" :description="__('room_audio.ceiling_hint')">
            <div class="rc-ceiling" wire:ignore>
                <input
                    type="number"
                    class="rc-field"
                    min="0"
                    max="98"
                    step="1"
                    x-ref="ceiling"
                    value="{{ $ceiling }}"
                    aria-label="{{ __('room_audio.ceiling') }}"
                >
                <x-filament::button color="gray" x-on:click="$wire.saveCeiling(parseInt($refs.ceiling.value, 10))">
                    {{ __('room_audio.ceiling_save') }}
                </x-filament::button>
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
