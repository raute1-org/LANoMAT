<x-filament-panels::page>
    <style>
        .ss-wrap { display: grid; gap: 1.5rem; max-width: 64rem; }
        .ss-form { display: grid; gap: 1rem; }
        .ss-row { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .ss-label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .375rem; }
        .ss-hint { font-size: .875rem; color: var(--gray-500); }
        .ss-status { font-size: .875rem; }
    </style>

    <div class="ss-wrap">
        <x-filament::section>
            <label for="server-settings-select" class="ss-label">{{ __('gameservers.settings.select_server') }}</label>
            @if ($servers->isEmpty())
                <p class="ss-hint">{{ __('gameservers.settings.no_servers') }}</p>
            @else
                <x-filament::input.wrapper>
                    <x-filament::input.select id="server-settings-select" wire:model.live="server">
                        <option value="">{{ __('gameservers.settings.select_placeholder') }}</option>
                        @foreach ($servers as $option)
                            <option value="{{ $option->id }}">{{ $option->title }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            @endif
        </x-filament::section>

        @if ($link !== null)
            @if (! $linked)
                <x-filament::section>
                    <p role="status">{{ __('gameservers.settings.not_linked') }}</p>
                </x-filament::section>
            @elseif ($loadFailed)
                <x-filament::section>
                    <p role="alert">{{ __('gameservers.settings.load_failed') }}</p>
                </x-filament::section>
            @else
                <x-filament::section :heading="__('gameservers.settings.power.heading')">
                    <div class="ss-row">
                        <span class="ss-status" role="status" aria-live="polite">
                            {{ __('gameservers.settings.status') }}:
                            <strong>{{ $state?->label() ?? __('gameservers.settings.status_unknown') }}</strong>
                        </span>
                        @if ($canPower)
                            <x-filament::button color="success" size="sm" wire:click="power('start')" wire:confirm="{{ __('gameservers.settings.power.confirm') }}">{{ __('gameservers.settings.power.start') }}</x-filament::button>
                            <x-filament::button color="danger" size="sm" wire:click="power('stop')" wire:confirm="{{ __('gameservers.settings.power.confirm') }}">{{ __('gameservers.settings.power.stop') }}</x-filament::button>
                            <x-filament::button color="warning" size="sm" wire:click="power('restart')" wire:confirm="{{ __('gameservers.settings.power.confirm') }}">{{ __('gameservers.settings.power.restart') }}</x-filament::button>
                        @endif
                    </div>
                </x-filament::section>

                @if ($canPower)
                    <x-filament::section :heading="__('gameservers.settings.restart_round.heading')">
                        <div class="ss-row">
                            <x-filament::button color="warning" size="sm" wire:click="restartRound" wire:confirm="{{ __('gameservers.settings.restart_round.confirm') }}">{{ __('gameservers.settings.restart_round.action') }}</x-filament::button>
                            @if ($canReloadMap)
                                <x-filament::button color="gray" size="sm" wire:click="reloadMap" wire:confirm="{{ __('gameservers.settings.reload.confirm') }}">{{ __('gameservers.settings.reload.action') }}</x-filament::button>
                            @endif
                        </div>
                    </x-filament::section>
                @endif

                <x-filament::section :heading="__('gameservers.settings.general.heading')" :description="__('gameservers.settings.general.help')">
                    <form wire:submit="saveSettings" class="ss-form">
                        {{ $this->settingsForm }}
                        <div class="ss-row">
                            <x-filament::button type="submit">{{ __('gameservers.settings.general.save') }}</x-filament::button>
                            <x-filament::button type="button" color="warning" wire:click="saveSettingsAndRestart" wire:confirm="{{ __('gameservers.settings.power.confirm') }}">{{ __('gameservers.settings.general.save_restart') }}</x-filament::button>
                        </div>
                    </form>
                </x-filament::section>

                <x-filament::section :heading="__('gameservers.settings.map.heading')" :description="__('gameservers.settings.map.help')">
                    <form wire:submit="changeMap" class="ss-form">
                        {{ $this->mapForm }}
                        <div>
                            <x-filament::button type="submit" color="gray">{{ __('gameservers.settings.map.change') }}</x-filament::button>
                        </div>
                    </form>
                </x-filament::section>

                @if ($isGunGame)
                    <x-filament::section :heading="__('gameservers.settings.gungame.heading')" :description="__('gameservers.settings.gungame.help')">
                        <form wire:submit="saveGunGame" class="ss-form">
                            {{ $this->gunGameForm }}
                            <div>
                                <x-filament::button type="submit">{{ __('gameservers.settings.gungame.save') }}</x-filament::button>
                            </div>
                        </form>
                    </x-filament::section>
                @endif
            @endif
        @endif
    </div>
</x-filament-panels::page>
