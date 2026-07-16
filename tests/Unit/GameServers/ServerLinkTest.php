<?php

use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Enums\ServerLinkStatus;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\Tournament;

it('creates a server link via the factory with join_info cast to JoinInfo', function () {
    $link = ServerLink::factory()->create([
        'join_info' => new JoinInfo(
            address: '10.0.0.5',
            port: 27015,
            password: 'secret',
            connectString: 'steam://connect/10.0.0.5:27015',
        ),
    ]);

    $fresh = $link->fresh();

    expect($fresh->join_info)->toBeInstanceOf(JoinInfo::class)
        ->and($fresh->join_info->address)->toBe('10.0.0.5')
        ->and($fresh->join_info->port)->toBe(27015)
        ->and($fresh->join_info->password)->toBe('secret')
        ->and($fresh->join_info->connectString)->toBe('steam://connect/10.0.0.5:27015');
});

it('decodes a null join_info to an empty JoinInfo', function () {
    $link = ServerLink::factory()->create(['join_info' => null]);

    expect($link->fresh()->join_info)->toBeInstanceOf(JoinInfo::class)
        ->and($link->fresh()->join_info->toArray())->toBe([]);
});

it('defaults status to Pending', function () {
    $link = ServerLink::factory()->create();

    expect($link->fresh()->status)->toBe(ServerLinkStatus::Pending);
});

it('has german labels for each ServerLinkStatus case', function () {
    expect(ServerLinkStatus::Pending->label())->toBe('Ausstehend')
        ->and(ServerLinkStatus::Provisioning->label())->toBe('Wird angelegt')
        ->and(ServerLinkStatus::Ready->label())->toBe('Bereit')
        ->and(ServerLinkStatus::Failed->label())->toBe('Fehlgeschlagen')
        ->and(ServerLinkStatus::Stopped->label())->toBe('Gestoppt');
});

it('resolves the match relation', function () {
    $match = GameMatch::factory()->create();
    $link = ServerLink::factory()->create(['match_id' => $match->id]);

    expect($link->match)->toBeInstanceOf(GameMatch::class)
        ->and($link->match->id)->toBe($match->id);
});

it('resolves the tournament relation', function () {
    $tournament = Tournament::factory()->create();
    $link = ServerLink::factory()->create(['tournament_id' => $tournament->id]);

    expect($link->tournament)->toBeInstanceOf(Tournament::class)
        ->and($link->tournament->id)->toBe($tournament->id);
});

it('resolves the serverLink relation from GameMatch', function () {
    $link = ServerLink::factory()->create();
    $match = GameMatch::factory()->create(['server_link_id' => $link->id]);

    expect($match->serverLink)->toBeInstanceOf(ServerLink::class)
        ->and($match->serverLink->id)->toBe($link->id);
});

it('has match_id, tournament_id, manual fillable but not pelican_server_id, join_info, status', function () {
    $link = new ServerLink;

    expect($link->getFillable())->toBe([
        'match_id',
        'tournament_id',
        'manual',
    ]);
});
