<?php

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\RepositoryEggs;
use App\Modules\GameServers\Testing\FakePelicanClient;

beforeEach(function () {
    $this->fake = new FakePelicanClient;
    $this->app->instance(PelicanClient::class, $this->fake);
});

it('imports every repository egg and is idempotent on a second run', function () {
    $this->artisan('gameservers:import-eggs')
        ->expectsOutputToContain('Garrys Mod (LANoMAT)')
        ->assertExitCode(0);

    expect($this->fake->importedEggs)->toHaveCount(1)
        ->and($this->fake->importedEggs[0])->toContain(RepositoryEggs::GMOD_UUID);

    $this->artisan('gameservers:import-eggs')->assertExitCode(0);

    // Re-import keeps exactly one egg with that uuid on the panel.
    expect(collect($this->fake->listEggs())->where('uuid', RepositoryEggs::GMOD_UUID))->toHaveCount(1);
});

it('prints the manual steps and passes when the key may not import but the egg exists', function () {
    $this->fake->eggs['7'] = new PelicanEgg('7', 'Garrys Mod (LANoMAT)', uuid: RepositoryEggs::GMOD_UUID);
    $this->fake->failNextImportWith(GameServerException::pelicanUnauthorized(403));

    $this->artisan('gameservers:import-eggs')
        ->expectsOutputToContain('Import from file')
        ->expectsOutputToContain('already on the panel')
        ->assertExitCode(0);
});

it('fails with the manual steps when the key may not import and the egg is missing', function () {
    $this->fake->failNextImportWith(GameServerException::pelicanUnauthorized(403));

    $this->artisan('gameservers:import-eggs')
        ->expectsOutputToContain('Import from file')
        ->expectsOutputToContain('missing')
        ->assertExitCode(1);
});

it('only checks with --check and imports nothing', function () {
    $this->fake->eggs['7'] = new PelicanEgg('7', 'Garrys Mod (LANoMAT)', uuid: RepositoryEggs::GMOD_UUID);

    $this->artisan('gameservers:import-eggs --check')->assertExitCode(0);
    expect($this->fake->importedEggs)->toBe([]);

    $this->fake->eggs = [];
    $this->artisan('gameservers:import-eggs --check')->assertExitCode(1);
});
