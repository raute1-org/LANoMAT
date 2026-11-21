@php
    /** @var \App\Modules\Lighting\Support\FixtureReport $report */
    $unreachable = ! $report->reachable;
    /** @var \App\Modules\Lighting\Support\Topology $topology */
    $extended = $topology->hasExtendedChannels();
@endphp

<x-filament-panels::page>
    <style>
        .lc-wrap { display: grid; gap: 1.5rem; max-width: 64rem; }
        .lc-status { display: flex; align-items: flex-start; gap: .75rem; }
        .lc-dot { width: .625rem; height: .625rem; border-radius: 9999px; margin-top: .4rem; flex: none; background: var(--success-600); }
        .lc-dot[data-error="true"] { background: var(--danger-600); }
        .lc-title { font-weight: 600; font-size: 1.125rem; }
        .lc-hint { color: var(--gray-500); font-size: .875rem; }
        .lc-fixtures { display: grid; gap: 1rem; grid-template-columns: repeat(auto-fit, minmax(11rem, 1fr)); }
        .lc-fixture { border: 1px solid var(--gray-300); border-radius: .5rem; padding: .75rem; display: grid; gap: .5rem; }
        .dark .lc-fixture { border-color: var(--gray-600); }
        .lc-swatch { height: 3rem; border-radius: .375rem; border: 1px solid var(--gray-300); }
        .dark .lc-swatch { border-color: var(--gray-600); }
        .lc-mono { font-family: ui-monospace, 'JetBrains Mono', monospace; font-variant-numeric: tabular-nums; }
        .lc-grid { display: grid; gap: 1.5rem; grid-template-columns: 1fr; }
        @media (min-width: 48rem) { .lc-grid { grid-template-columns: 1fr 1fr; } }
        .lc-row { display: flex; gap: .75rem; align-items: center; flex-wrap: wrap; }
        .lc-label { display: block; font-weight: 500; font-size: .875rem; margin-bottom: .25rem; }
        .lc-field { min-height: 2.5rem; padding: .25rem .5rem; border-radius: .5rem; border: 1px solid var(--gray-300); background: transparent; color: inherit; }
        .dark .lc-field { border-color: var(--gray-600); }
        .lc-field option { color: var(--gray-950); }
        .lc-color { width: 3.5rem; height: 2.5rem; padding: 0; border: 1px solid var(--gray-300); border-radius: .5rem; background: transparent; }
        .lc-range { width: 100%; accent-color: var(--primary-600); min-height: 2rem; }
        .lc-field:focus-visible, .lc-range:focus-visible, .lc-color:focus-visible { outline: 2px solid var(--primary-600); outline-offset: 2px; }
        .lc-blackout { min-height: 4rem; width: 100%; font-size: 1.25rem; font-weight: 700; }
        .lc-warn { border-left: 4px solid var(--warning-600); padding: .5rem .75rem; font-size: .875rem; }
        .lc-busy { color: var(--gray-500); font-size: .875rem; }
        @media (prefers-reduced-motion: reduce) { .lc-wrap * { transition: none !important; animation: none !important; } }
    </style>

    <div class="lc-wrap" wire:poll.{{ $pollSeconds }}s>
        <x-filament::section>
            <div class="lc-status" role="status" aria-live="polite">
                <span class="lc-dot" data-error="{{ $unreachable ? 'true' : 'false' }}" aria-hidden="true"></span>
                <div>
                    @if (! $enabled || ! $configured)
                        <p class="lc-title">{{ __('lighting.state.disabled') }}</p>
                        <p class="lc-hint">{{ __('lighting.state.disabled_hint') }}</p>
                    @elseif ($unreachable)
                        <p class="lc-title">{{ __('lighting.state.unreachable') }}</p>
                        <p class="lc-hint">{{ __('lighting.state.unreachable_hint') }}</p>
                    @else
                        <p class="lc-title">{{ __('lighting.state.online') }}</p>
                        <p class="lc-hint">{{ __('lighting.state.checked_at', ['time' => $report->checkedAt->setTimezone(config('lanomat.timezone'))->format('H:i:s')]) }}</p>
                    @endif
                </div>
                <span class="lc-busy" style="margin-left:auto" wire:loading.delay.longer>{{ __('lighting.state.loading') }}</span>
            </div>
        </x-filament::section>

        <x-filament::section :heading="__('lighting.fixtures')">
            <div class="lc-fixtures">
                @forelse ($report->fixtures as $fixture)
                    <div class="lc-fixture" wire:key="fx-{{ $fixture->id }}">
                        <strong>{{ __('lighting.fixture', ['id' => $fixture->id]) }}</strong>
                        <div class="lc-swatch" style="background: {{ $fixture->state->hex ?? 'transparent' }}" role="img" aria-label="{{ __('lighting.color') }} {{ $fixture->state->hex ?? '-' }}"></div>
                        <span class="lc-mono">{{ $fixture->state->hex ?? '-' }}</span>
@if ($extended)
                        <span class="lc-hint">{{ __('lighting.brightness') }}: <span class="lc-mono">{{ $fixture->state->brightness === null ? '-' : round($fixture->state->brightness * 100).' %' }}</span></span>
                        <span class="lc-hint">{{ __('lighting.strobe.label') }}: <span class="lc-mono">{{ ($fixture->state->strobe ?? 0) > 0 ? $fixture->state->strobe : __('lighting.strobe.off') }}</span></span>
                        @endif
                    </div>
                @empty
                    <p class="lc-hint">{{ __('lighting.no_fixtures') }}</p>
                @endforelse
            </div>
        </x-filament::section>

        <x-filament::section>
            <x-filament::button class="lc-blackout" color="danger" wire:click="blackout" wire:confirm="{{ __('lighting.blackout_confirm') }}" :disabled="$unreachable">
                {{ __('lighting.blackout') }}
            </x-filament::button>
        </x-filament::section>

        <div class="lc-grid" x-data="{ target: 'all', hex: '#ff5520', brightness: 0.8, temperature: 0.5, white: 0.5, a: '#00ff00', b: '#ff0000', patternBrightness: 1, strobe: 0 }">
            <x-filament::section :heading="__('lighting.control')">
                <div style="display:grid; gap:1rem">
                    <div>
                        <label class="lc-label" for="lc-target">{{ __('lighting.target') }}</label>
                        <select id="lc-target" class="lc-field" x-model="target" @disabled($unreachable)>
                            <option value="all">{{ __('lighting.all') }}</option>
                            @foreach ($topology->ids() as $i)
                                <option value="{{ $i }}">{{ __('lighting.fixture', ['id' => $i]) }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="lc-row">
                        <input type="color" class="lc-color" x-model="hex" aria-label="{{ __('lighting.color') }}" @disabled($unreachable)>
                        @if ($topology->supportsBrightness())
                            <div style="flex:1; min-width: 8rem">
                                <label class="lc-label" for="lc-brightness">{{ __('lighting.brightness') }}</label>
                                <input id="lc-brightness" type="range" class="lc-range" min="0" max="1" step="0.05" x-model.number="brightness" @disabled($unreachable)>
                            </div>
                        @endif
                        <x-filament::button x-on:click="$wire.applyColor(target, hex, {{ $topology->supportsBrightness() ? 'brightness' : 'null' }})" :disabled="$unreachable">{{ __('lighting.apply') }}</x-filament::button>
                    </div>
                    @if ($topology->supportsWhite())
                    <div class="lc-row">
                        <div style="flex:1; min-width: 8rem">
                            <label class="lc-label" for="lc-temp">{{ __('lighting.temperature') }}</label>
                            <input id="lc-temp" type="range" class="lc-range" min="0" max="1" step="0.05" x-model.number="temperature" @disabled($unreachable)>
                        </div>
                        <div style="flex:1; min-width: 8rem">
                            <label class="lc-label" for="lc-white">{{ __('lighting.white_level') }}</label>
                            <input id="lc-white" type="range" class="lc-range" min="0" max="1" step="0.05" x-model.number="white" @disabled($unreachable)>
                        </div>
                        <x-filament::button color="gray" x-on:click="$wire.applyWhite(target, temperature, white)" :disabled="$unreachable">{{ __('lighting.apply') }}</x-filament::button>
                    </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('lighting.pattern')" :description="__('lighting.pattern_hint')">
                <div class="lc-row">
                    <input type="color" class="lc-color" x-model="a" aria-label="{{ __('lighting.pattern_a') }}" @disabled($unreachable)>
                    <input type="color" class="lc-color" x-model="b" aria-label="{{ __('lighting.pattern_b') }}" @disabled($unreachable)>
                    @if ($topology->supportsBrightness())
                        <div style="flex:1; min-width: 6rem">
                            <label class="lc-label" for="lc-pb">{{ __('lighting.brightness') }}</label>
                            <input id="lc-pb" type="range" class="lc-range" min="0" max="1" step="0.05" x-model.number="patternBrightness" @disabled($unreachable)>
                        </div>
                    @endif
                    <x-filament::button x-on:click="$wire.applyPattern(a, b, patternBrightness)" :disabled="$unreachable">{{ __('lighting.apply') }}</x-filament::button>
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('lighting.strobe.label')">
                <div style="display:grid; gap:.75rem">
                    <p class="lc-warn" role="note">{{ __('lighting.strobe.warning') }}</p>
                    @if (! $topology->supportsStrobe())
                        <p class="lc-hint">{{ __('lighting.strobe.unsupported') }}</p>
                    @elseif ($strobeAllowed)
                        <div class="lc-row">
                            <div style="flex:1; min-width: 8rem">
                                <label class="lc-label" for="lc-strobe">{{ __('lighting.strobe.speed', ['max' => $maxStrobe]) }}</label>
                                <input id="lc-strobe" type="range" class="lc-range" min="0" max="{{ $maxStrobe }}" step="1" x-model.number="strobe" @disabled($unreachable)>
                            </div>
                            <x-filament::button color="warning" x-on:click="$wire.applyStrobe(target, strobe)" :disabled="$unreachable">{{ __('lighting.apply') }}</x-filament::button>
                        </div>
                    @else
                        <p class="lc-hint">{{ __('lighting.strobe.locked') }}</p>
                    @endif
                    @if ($topology->supportsStrobe())
                    <div>
                        <x-filament::button color="gray" wire:click="toggleStrobePermission" wire:confirm="{{ $strobeAllowed ? __('lighting.strobe.disable_confirm') : __('lighting.strobe.enable_confirm') }}">
                            {{ $strobeAllowed ? __('lighting.strobe.disable') : __('lighting.strobe.enable') }}
                        </x-filament::button>
                    </div>
                    @endif
                </div>
            </x-filament::section>

            <x-filament::section :heading="__('lighting.game.heading')" :description="__('lighting.game.hint')">
                <div class="lc-row" x-data="{ mode: '' }">
                    @if ($game->reachable)
                        <span class="lc-hint" role="status">{{ $game->active ? __('lighting.game.running', ['mode' => $game->mode ?? '-']) : __('lighting.game.idle') }}</span>
                    @endif
                    @if ($game->modes !== [])
                        <select class="lc-field" x-model="mode" aria-label="{{ __('lighting.game.mode') }}" @disabled($unreachable)>
                            <option value="">{{ __('lighting.game.mode_default') }}</option>
                            @foreach ($game->modes as $gameMode)
                                <option value="{{ $gameMode }}">{{ $gameMode }}</option>
                            @endforeach
                        </select>
                    @endif
                    <x-filament::button x-on:click="$wire.startGame(mode)" :disabled="$unreachable">{{ __('lighting.game.start') }}</x-filament::button>
                    <x-filament::button color="gray" wire:click="cancelGame" :disabled="$unreachable">{{ __('lighting.game.cancel') }}</x-filament::button>
                </div>
            </x-filament::section>
        </div>

        <x-filament::section :heading="__('lighting.scene.plural')">
            <div class="lc-row">
                @forelse ($scenes as $scene)
                    <x-filament::button color="gray" wire:key="scene-{{ $scene->id }}" wire:click="playScene({{ $scene->id }})" :disabled="$unreachable" :title="$scene->description">
                        {{ $scene->name }}
                    </x-filament::button>
                @empty
                    <p class="lc-hint">{{ __('lighting.scene.empty') }}</p>
                @endforelse
            </div>
        </x-filament::section>
    </div>
</x-filament-panels::page>
