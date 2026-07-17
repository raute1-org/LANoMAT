<?php

declare(strict_types=1);

namespace App\Modules\Voice;

use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\Domain\VoiceProvider;
use Illuminate\Contracts\Container\Container;

class VoiceProviders
{
    public function __construct(private Container $app) {}

    /**
     * @return array<string, VoiceClient>
     */
    public function active(): array
    {
        $clients = [];
        foreach (VoiceProvider::active() as $provider) {
            $clients[$provider->value] = $this->for($provider);
        }

        return $clients;
    }

    public function for(VoiceProvider $provider): VoiceClient
    {
        return match ($provider) {
            VoiceProvider::Mumble => $this->app->make(HttpMumbleClient::class, [
                'baseUrl' => (string) config('services.mumble.rest_url'),
                'token' => (string) config('services.mumble.ice_secret'),
            ]),
            VoiceProvider::TeamSpeak => $this->app->make(HttpTeamSpeakClient::class, [
                'baseUrl' => (string) config('services.teamspeak.rest_url'),
                'token' => (string) config('services.teamspeak.token'),
            ]),
        };
    }
}
