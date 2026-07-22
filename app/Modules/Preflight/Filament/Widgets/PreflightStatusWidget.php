<?php

namespace App\Modules\Preflight\Filament\Widgets;

use App\Modules\Preflight\Actions\RunPreflight;
use Filament\Widgets\Widget;
use Illuminate\Support\Facades\Cache;

class PreflightStatusWidget extends Widget
{
    protected string $view = 'filament.widgets.preflight-status';

    protected int|string|array $columnSpan = 'full';

    /**
     * @return list<array{key: string, label: string, status: string, message: string}>
     */
    public function results(): array
    {
        return Cache::remember('preflight.results', now()->addSeconds(15), fn () => app(RunPreflight::class)->handle());
    }

    public function refresh(): void
    {
        Cache::forget('preflight.results');
    }
}
