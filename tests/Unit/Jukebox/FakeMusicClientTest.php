<?php

declare(strict_types=1);

use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\Support\TrackDto;

it('records the synced queue order and returns queued search results', function () {
    $fake = fakeMusic();
    $fake->willReturnSearch([new TrackDto(uri: 'ma://track/1', title: 'Song', artist: 'X')]);

    expect(app(MusicClient::class)->search('song'))->toHaveCount(1);
    app(MusicClient::class)->syncQueue(['ma://track/1', 'ma://track/2']);
    expect($fake->syncedQueue())->toBe(['ma://track/1', 'ma://track/2']);
});
