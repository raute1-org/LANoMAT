<?php

use App\Modules\Voice\Domain\VoiceChannel;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Support\VoiceServerSightings;
use App\Modules\Voice\Testing\FakeVoiceClient;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Support\Facades\Cache;

function breakerClient(): FakeVoiceClient
{
    return new class(VoiceProvider::TeamSpeak) extends FakeVoiceClient
    {
        public int $calls = 0;

        public bool $failing = true;

        /** @return array<int, VoiceChannel> */
        public function listChannels(): array
        {
            $this->calls++;

            if ($this->failing) {
                throw new RuntimeException('sidecar timed out');
            }

            return parent::listChannels();
        }
    };
}

function breakerSource(FakeVoiceClient $client): VoiceServerSightings
{
    config(['services.voice.providers' => ['teamspeak']]);

    $providers = new class($client) extends VoiceProviders
    {
        public function __construct(private readonly FakeVoiceClient $client) {}

        public function for(VoiceProvider $provider): FakeVoiceClient
        {
            return $this->client;
        }
    };

    return new VoiceServerSightings(VoiceProvider::TeamSpeak, $providers);
}

beforeEach(fn () => Cache::flush());

it('asks a failing voice provider only once per breaker window', function () {
    $client = breakerClient();
    $source = breakerSource($client);

    expect(fn () => $source->sightings())->toThrow(RuntimeException::class);
    expect($source->sightings())->toBe([])->and($client->calls)->toBe(1);
});

it('asks again once the breaker has expired', function () {
    $client = breakerClient();
    $source = breakerSource($client);

    expect(fn () => $source->sightings())->toThrow(RuntimeException::class);

    Cache::forget('presence.voice.down.teamspeak');
    $client->failing = false;

    expect($source->sightings())->toBeArray()->and($client->calls)->toBe(2);
});
