<?php

namespace App\Modules\Preflight\Checks;

use App\Modules\Preflight\Contracts\HealthCheck;
use App\Modules\Preflight\Support\HealthResult;
use Illuminate\Support\Facades\Http;
use Throwable;

class DiscordApiCheck implements HealthCheck
{
    public function key(): string
    {
        return 'discord_api';
    }

    public function label(): string
    {
        return __('preflight.checks.discord_api');
    }

    public function run(): HealthResult
    {
        $token = config('services.discord.bot_token');

        if (blank($token)) {
            return HealthResult::skipped(__('preflight.messages.not_configured'));
        }

        try {
            $response = Http::withHeaders(['Authorization' => "Bot {$token}"])
                ->timeout(3)
                ->get('https://discord.com/api/v10/users/@me');

            return $response->successful()
                ? HealthResult::ok()
                : HealthResult::down("HTTP {$response->status()}");
        } catch (Throwable $e) {
            return HealthResult::down($e->getMessage());
        }
    }
}
