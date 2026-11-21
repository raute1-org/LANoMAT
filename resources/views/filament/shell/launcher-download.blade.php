@if (app(\App\Support\Modules\Modules::class)->routable(\App\Support\Modules\Module::GameRecipes))
    <x-filament::button
        tag="a"
        :href="\App\Support\Brand\ShellParity::LAUNCHER_DOWNLOAD_URL"
        color="primary"
        size="sm"
        icon="heroicon-o-arrow-down-tray"
        data-shell="launcher-download"
    >
        {{ \App\Support\Brand\ShellParity::LAUNCHER_DOWNLOAD_LABEL }}
    </x-filament::button>
@endif
