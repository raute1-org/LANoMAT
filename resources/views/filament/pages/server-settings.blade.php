<x-filament-panels::page>
    <style>
        .ss-wrap { display: grid; gap: 1.5rem; max-width: 64rem; }
        .ss-form { display: grid; gap: 1rem; }
        .ss-row { display: flex; flex-wrap: wrap; align-items: center; gap: .75rem; }
        .ss-label { display: block; font-size: .875rem; font-weight: 500; margin-bottom: .375rem; }
        .ss-hint { font-size: .875rem; color: var(--gray-500); }
        .ss-status { font-size: .875rem; }
        .ss-pre { font-family: ui-monospace, 'JetBrains Mono', monospace; font-size: .8125rem; white-space: pre-wrap; overflow-wrap: anywhere; max-height: 24rem; overflow: auto; padding: .75rem; border-radius: .5rem; background: var(--gray-50); border: 1px solid var(--gray-200); }
        .dark .ss-pre { background: var(--gray-900); border-color: var(--gray-700); }
        .ss-history { list-style: none; margin: 0; padding: 0; display: grid; gap: .25rem; font-family: ui-monospace, 'JetBrains Mono', monospace; font-size: .8125rem; }
        .ss-history button { text-align: left; background: none; border: 0; padding: .125rem 0; cursor: pointer; color: var(--gray-500); }
        .ss-history button:hover, .ss-history button:focus-visible { color: inherit; text-decoration: underline; }
        .ss-input-grow { flex: 1 1 18rem; min-width: 0; }
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
            @if ($remote)
                <x-filament::section :heading="__('gameservers.remote.heading')">
                    <div class="ss-row">
                        <span class="ss-status" role="status" aria-live="polite">
                            {{ __('gameservers.remote.status') }}:
                            <strong>{{ $remoteStateLabel }}</strong>
                        </span>
                        @if ($canPower)
                            <x-filament::button color="success" size="sm" wire:click="remotePower('start')" wire:loading.attr="disabled" wire:target="remotePower">{{ __('gameservers.remote.start') }}</x-filament::button>
                            <x-filament::button color="danger" size="sm" wire:click="remotePower('stop')" wire:confirm="{{ __('gameservers.remote.stop_confirm') }}" wire:loading.attr="disabled" wire:target="remotePower">{{ __('gameservers.remote.stop') }}</x-filament::button>
                            <x-filament::button color="warning" size="sm" wire:click="remotePower('restart')" wire:confirm="{{ __('gameservers.remote.restart_confirm') }}" wire:loading.attr="disabled" wire:target="remotePower">{{ __('gameservers.remote.restart') }}</x-filament::button>
                        @endif
                    </div>
                </x-filament::section>

                @if ($canPower)
                    <x-filament::section :heading="__('gameservers.remote.console')" :description="__('gameservers.remote.command_help')">
                        <form wire:submit="sendConsole" class="ss-form">
                            @if ($quickCommands !== [])
                                <div>
                                    <label for="remote-quick" class="ss-label">{{ __('gameservers.remote.quick') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input.select id="remote-quick" x-on:change="$wire.useQuickCommand($event.target.value); $event.target.value = ''">
                                            <option value="">{{ __('gameservers.remote.quick_placeholder') }}</option>
                                            @foreach ($quickCommands as $quick)
                                                <option value="{{ $quick }}">{{ __('gameservers.remote.quick_commands.'.trim($quick)) }}</option>
                                            @endforeach
                                        </x-filament::input.select>
                                    </x-filament::input.wrapper>
                                    <p class="ss-hint">{{ __('gameservers.remote.quick_needs_argument') }}</p>
                                </div>
                            @endif
                            <div class="ss-row">
                                <div class="ss-input-grow">
                                    <label for="remote-command" class="ss-label">{{ __('gameservers.remote.command') }}</label>
                                    <x-filament::input.wrapper>
                                        <x-filament::input id="remote-command" type="text" wire:model="consoleCommand" maxlength="256" autocomplete="off" spellcheck="false" style="font-family: ui-monospace, monospace" />
                                    </x-filament::input.wrapper>
                                </div>
                                <x-filament::button type="submit" wire:loading.attr="disabled" wire:target="sendConsole">
                                    <span wire:loading.remove wire:target="sendConsole">{{ __('gameservers.remote.send') }}</span>
                                    <span wire:loading wire:target="sendConsole">{{ __('gameservers.remote.sending') }}</span>
                                </x-filament::button>
                            </div>
                        </form>
                        @if ($consoleOutput !== null)
                            <p class="ss-label" style="margin-top: 1rem">{{ __('gameservers.remote.answer') }}</p>
                            <pre class="ss-pre" role="log" aria-live="polite">{{ $consoleOutput !== '' ? $consoleOutput : __('gameservers.remote.no_answer') }}</pre>
                        @endif
                        @if ($consoleHistory !== [])
                            <p class="ss-label" style="margin-top: 1rem">{{ __('gameservers.remote.history') }}</p>
                            <ul class="ss-history">
                                @foreach ($consoleHistory as $past)
                                    <li><button type="button" wire:click="useQuickCommand(@js($past))">{{ $past }}</button></li>
                                @endforeach
                            </ul>
                        @endif
                    </x-filament::section>

                    <x-filament::section :heading="__('gameservers.remote.logs')" collapsible collapsed>
                        <div class="ss-row">
                            <div>
                                <label for="remote-log-lines" class="ss-label">{{ __('gameservers.remote.logs_lines') }}</label>
                                <x-filament::input.wrapper>
                                    <x-filament::input id="remote-log-lines" type="number" wire:model="logLines" min="1" max="500" />
                                </x-filament::input.wrapper>
                            </div>
                            <x-filament::button color="gray" wire:click="loadLogs" wire:loading.attr="disabled" wire:target="loadLogs">
                                <span wire:loading.remove wire:target="loadLogs">{{ __('gameservers.remote.logs_load') }}</span>
                                <span wire:loading wire:target="loadLogs">{{ __('gameservers.remote.logs_loading') }}</span>
                            </x-filament::button>
                        </div>
                        @if ($logOutput !== null)
                            <pre class="ss-pre" style="margin-top: 1rem" role="log">{{ $logOutput !== '' ? $logOutput : __('gameservers.remote.logs_empty') }}</pre>
                        @endif
                    </x-filament::section>
                @endif
            @elseif (! $linked)
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
