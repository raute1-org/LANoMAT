<?php

declare(strict_types=1);

use App\Modules\Events\Models\Event;
use App\Modules\GameServers\Actions\DeprovisionServer;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Voice\Jobs\CleanupEventServerVoiceJob;
use App\Modules\Voice\Jobs\ProvisionEventServerVoiceJob;
use App\Modules\Voice\Listeners\CleanupServerVoiceOnStopped;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use App\Modules\Voice\Models\VoiceServerChannel;
use App\Modules\Voice\VoiceProviders;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;

uses(RefreshDatabase::class);

function eventServerLink(array $attributes = []): ServerLink
{
    return ServerLink::factory()->create(array_merge([
        'event_id' => Event::factory()->create()->id,
        'match_id' => null,
        'title' => 'QA-TTT-Test',
        'status' => ServerLinkStatus::Ready,
    ], $attributes));
}

it('queues a voice channel job when an event-level server (no match) turns Ready', function () {
    Bus::fake([ProvisionEventServerVoiceJob::class]);
    $link = eventServerLink();

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated($link));

    Bus::assertDispatched(ProvisionEventServerVoiceJob::class, fn ($job) => $job->serverLinkId === $link->id);
});

it('does not queue the event-server job for a server that is not Ready or has no event', function () {
    Bus::fake([ProvisionEventServerVoiceJob::class]);

    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated(eventServerLink(['status' => ServerLinkStatus::Provisioning])));
    (new ProvisionServerVoiceOnReady)->handle(new ServerLinkUpdated(eventServerLink(['event_id' => null])));

    Bus::assertNotDispatched(ProvisionEventServerVoiceJob::class);
});

it('creates one channel named after the server on every active provider and remembers the ids', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $link = eventServerLink();

    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle(app(VoiceProviders::class));

    foreach ($fakes as $fake) {
        $fake->assertChannelCreated('🎮 QA-TTT-Test');
    }

    expect(VoiceServerChannel::query()->where('server_link_id', $link->id)->count())->toBe(2);
});

it('is idempotent per provider on a re-fired Ready', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $link = eventServerLink();
    $providers = app(VoiceProviders::class);

    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle($providers);
    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle($providers);

    foreach ($fakes as $fake) {
        expect(collect($fake->channels))->toHaveCount(1);
    }
    expect(VoiceServerChannel::query()->count())->toBe(2);
});

it('provisions a provider that was added after the first run', function () {
    $fakes = fakeVoice(['mumble']);
    $link = eventServerLink();
    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle(app(VoiceProviders::class));

    $fakes = fakeVoice(['mumble', 'teamspeak']);
    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle(app(VoiceProviders::class));

    expect(VoiceServerChannel::query()->where('server_link_id', $link->id)->pluck('provider')->sort()->values()->all())
        ->toBe(['mumble', 'teamspeak']);
});

it('deletes the channels and forgets the ids when the server is stopped', function () {
    $fakes = fakeVoice(['mumble', 'teamspeak']);
    $link = eventServerLink();
    $providers = app(VoiceProviders::class);
    (new ProvisionEventServerVoiceJob($link->id, (string) $link->title))->handle($providers);

    $ids = VoiceServerChannel::query()->pluck('channel_id', 'provider');

    (new CleanupEventServerVoiceJob($link->id))->handle($providers);

    foreach ($fakes as $value => $fake) {
        $fake->assertChannelDeleted((int) $ids[$value]);
    }
    expect(VoiceServerChannel::query()->count())->toBe(0);
});

it('cleans up only when a server turns Stopped', function () {
    Bus::fake([CleanupEventServerVoiceJob::class]);

    (new CleanupServerVoiceOnStopped)->handle(new ServerLinkUpdated(eventServerLink(['status' => ServerLinkStatus::Ready])));
    Bus::assertNotDispatched(CleanupEventServerVoiceJob::class);

    $stopped = eventServerLink(['status' => ServerLinkStatus::Stopped]);
    (new CleanupServerVoiceOnStopped)->handle(new ServerLinkUpdated($stopped));
    Bus::assertDispatched(CleanupEventServerVoiceJob::class, fn ($job) => $job->serverLinkId === $stopped->id);
});

it('announces a deprovisioned server so listeners can react', function () {
    Illuminate\Support\Facades\Event::fake([ServerLinkUpdated::class]);
    app()->bind(PelicanClient::class, fn () => Mockery::mock(PelicanClient::class)->shouldIgnoreMissing());
    $link = eventServerLink(['pelican_server_id' => null]);

    (new DeprovisionServer)->handle($link);

    Illuminate\Support\Facades\Event::assertDispatched(ServerLinkUpdated::class, fn ($e) => $e->serverLink->status === ServerLinkStatus::Stopped);
});
