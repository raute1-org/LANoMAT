@if (app(\App\Support\Modules\Modules::class)->routable(\App\Support\Modules\Module::WebVoice) || app(\App\Support\Modules\Modules::class)->routable(\App\Support\Modules\Module::GameRecipes))
    <x-filament::button
        tag="a"
        :href="\App\Support\Brand\ShellParity::APP_DOWNLOAD_URL"
        color="primary"
        size="sm"
        icon="heroicon-o-arrow-down-tray"
        data-shell="app-download"
    >
        <span class="lanomat-app-dl-long">{{ \App\Support\Brand\ShellParity::APP_DOWNLOAD_LABEL }}</span>
        <span class="lanomat-app-dl-short">{{ \App\Support\Brand\ShellParity::APP_DOWNLOAD_SHORT_LABEL }}</span>
    </x-filament::button>
    {{-- Short label on narrow headers; plain CSS because Filament's build has no app Tailwind utilities. --}}
    <style>
        .lanomat-app-dl-short { display: none; }
        @media (max-width: 639px) {
            .lanomat-app-dl-long { display: none; }
            .lanomat-app-dl-short { display: inline; }
        }
    </style>
@endif
