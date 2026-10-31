<?php

use App\Modules\Games\Contracts\EggCatalog;
use App\Modules\Games\Support\NullEggCatalog;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\PelicanEggCatalog;
use App\Modules\GameServers\Testing\FakePelicanClient;
use App\Support\Modules\Modules;
use Illuminate\Support\Facades\Cache;

beforeEach(function () {
    Cache::flush();
    $this->fake = new FakePelicanClient;
    $this->app->instance(PelicanClient::class, $this->fake);
});

it('lists panel eggs by uuid and caches the result', function () {
    $this->fake->eggs['1'] = new PelicanEgg('1', 'Vanilla', uuid: 'u-1');
    $this->fake->eggs['2'] = new PelicanEgg('2', 'Without uuid');

    expect(app(PelicanEggCatalog::class)->options())->toBe(['u-1' => 'Vanilla']);

    $this->fake->eggs['3'] = new PelicanEgg('3', 'Added later', uuid: 'u-3');
    expect((new PelicanEggCatalog($this->fake))->options())->toBe(['u-1' => 'Vanilla']);
});

it('returns null instead of throwing when the panel cannot be reached', function () {
    $client = Mockery::mock(PelicanClient::class);
    $client->shouldReceive('listEggs')->once()->andThrow(GameServerException::pelicanUnavailable(null, 'down'));

    $catalog = new PelicanEggCatalog($client);

    expect($catalog->options())->toBeNull()
        ->and($catalog->options())->toBeNull();
});

it('binds the panel catalog only while the GameServers module is enabled', function () {
    expect(app(EggCatalog::class))->toBeInstanceOf(PelicanEggCatalog::class);

    app()->forgetScopedInstances();
    app()->instance(Modules::class, new Modules('core'));

    expect(app(EggCatalog::class))->toBeInstanceOf(NullEggCatalog::class);
});
