<?php

use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Domain\PelicanServer;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerState;
use App\Modules\GameServers\Testing\FakePelicanClient;
use PHPUnit\Framework\ExpectationFailedException;

it('creates a server, records it and returns a provisioning PelicanServer', function () {
    $fake = new FakePelicanClient;

    $server = $fake->createServer('5', ['name' => 'CS2 Server']);

    expect($server)->toBeInstanceOf(PelicanServer::class)
        ->and($server->state)->toBe(ServerState::Provisioning)
        ->and($fake->created)->toHaveCount(1)
        ->and($fake->created[0]['eggId'])->toBe('5')
        ->and($fake->created[0]['config'])->toBe(['name' => 'CS2 Server']);
});

it('passes the optional nodeId through to the created record', function () {
    $fake = new FakePelicanClient;

    $fake->createServer('5', [], '2');

    expect($fake->created[0]['nodeId'])->toBe('2');
});

it('reflects a settable state transition from provisioning to running', function () {
    $fake = new FakePelicanClient;
    $server = $fake->createServer('5', []);

    expect($fake->getServer($server->id)->state)->toBe(ServerState::Provisioning);

    $fake->setState($server->id, ServerState::Running);

    expect($fake->getServer($server->id)->state)->toBe(ServerState::Running);
});

it('records and asserts a power action', function () {
    $fake = new FakePelicanClient;
    $server = $fake->createServer('5', []);

    $fake->powerAction($server->id, PowerAction::Start);

    expect($fake->powerActions)->toHaveCount(1);
    $fake->assertPowerAction($server->id, PowerAction::Start);
});

it('fails assertPowerAction when no matching action was recorded', function () {
    $fake = new FakePelicanClient;
    $server = $fake->createServer('5', []);

    $fake->powerAction($server->id, PowerAction::Start);

    $fake->assertPowerAction($server->id, PowerAction::Stop);
})->throws(ExpectationFailedException::class);

it('records and asserts a server deletion', function () {
    $fake = new FakePelicanClient;
    $server = $fake->createServer('5', []);

    $fake->deleteServer($server->id);

    expect($fake->deleted)->toBe([$server->id]);
    $fake->assertServerDeleted($server->id);
});

it('fails assertServerDeleted when the server was never deleted', function () {
    $fake = new FakePelicanClient;
    $server = $fake->createServer('5', []);

    $fake->assertServerDeleted($server->id);
})->throws(ExpectationFailedException::class);

it('asserts a server was created optionally filtered by eggId', function () {
    $fake = new FakePelicanClient;
    $fake->createServer('5', []);

    $fake->assertServerCreated('5');
});

it('fails assertServerCreated when the eggId does not match', function () {
    $fake = new FakePelicanClient;
    $fake->createServer('5', []);

    $fake->assertServerCreated('6');
})->throws(ExpectationFailedException::class);

it('asserts nothing was created on a fresh fake', function () {
    $fake = new FakePelicanClient;

    $fake->assertNothingCreated();
});

it('fails assertNothingCreated once a server has been created', function () {
    $fake = new FakePelicanClient;
    $fake->createServer('5', []);

    $fake->assertNothingCreated();
})->throws(ExpectationFailedException::class);

it('fakePelican helper swaps the PelicanClient binding', function () {
    $fake = fakePelican();

    $client = app(PelicanClient::class);

    expect($client)->toBe($fake)
        ->and($client)->toBeInstanceOf(FakePelicanClient::class);
});
