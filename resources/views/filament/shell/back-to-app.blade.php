{{-- Always-visible way back to the participant app. Filament-native button, no app Tailwind. --}}
<x-filament::button tag="a" href="/" color="gray" size="sm" icon="heroicon-o-arrow-uturn-left" data-shell="back-to-app">
    {{ \App\Support\Brand\ShellParity::FILAMENT_BACK_LABEL }}
</x-filament::button>
