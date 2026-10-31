<?php

declare(strict_types=1);

use App\Modules\WebVoice\Domain\WebVoiceUnavailable;
use App\Modules\WebVoice\Support\LiveKitClient;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function liveKit(): LiveKitClient
{
    return new LiveKitClient(
        apiUrl: 'http://livekit.test:7880',
        apiKey: 'test-key',
        apiSecret: str_repeat('s', 32),
        tokenTtl: 600,
        timeout: 3,
    );
}

/** @return array<string, mixed> */
function decodeToken(string $jwt): array
{
    return (array) JWT::decode($jwt, new Key(str_repeat('s', 32), 'HS256'));
}

it('signs a short-lived join token with exactly the grants a speaker needs', function () {
    $token = decodeToken(liveKit()->issueToken('lanomat-7', 'u42', 'Ada', true));
    $video = (array) $token['video'];

    expect($token['iss'])->toBe('test-key')
        ->and($token['sub'])->toBe('u42')
        ->and($token['name'])->toBe('Ada')
        ->and($token['exp'] - $token['nbf'])->toBe(600)
        ->and($video)->toBe([
            'roomJoin' => true,
            'room' => 'lanomat-7',
            'canSubscribe' => true,
            'canPublish' => true,
            'canPublishData' => false,
            'canPublishSources' => ['microphone'],
            'canUpdateOwnMetadata' => false,
        ]);
});

it('withholds publish rights from a muted participant but keeps listening', function () {
    $video = (array) decodeToken(liveKit()->issueToken('lanomat-7', 'u42', 'Ada', false))['video'];

    expect($video['canPublish'])->toBeFalse()->and($video['canSubscribe'])->toBeTrue();
});

it('refuses to sign when the key or secret is missing', function () {
    $client = new LiveKitClient('http://x', '', '', 600, 3);

    $client->issueToken('lanomat-7', 'u1', 'Ada', true);
})->throws(WebVoiceUnavailable::class);

it('lists the participants of a room through the admin API with a room-scoped admin token', function () {
    Http::fake(['livekit.test:7880/*' => Http::response(['participants' => [
        ['identity' => 'u1', 'name' => 'Ada', 'permission' => ['canPublish' => true]],
        ['identity' => 'u2', 'name' => 'Bob', 'permission' => ['canPublish' => false]],
    ]])]);

    $participants = liveKit()->participants('lanomat-7');

    expect($participants)->toHaveCount(2)
        ->and($participants[0]->userId())->toBe(1)
        ->and($participants[1]->canPublish)->toBeFalse();

    Http::assertSent(function (Request $request) {
        $claims = decodeToken(str_replace('Bearer ', '', $request->header('Authorization')[0]));
        $video = (array) $claims['video'];

        return $request->url() === 'http://livekit.test:7880/twirp/livekit.RoomService/ListParticipants'
            && $request->data() === ['room' => 'lanomat-7']
            && $video['roomAdmin'] === true && $video['room'] === 'lanomat-7';
    });
});

it('treats a room that does not exist yet as empty', function () {
    Http::fake(['livekit.test:7880/*' => Http::response(['code' => 'not_found', 'msg' => 'room not found'], 404)]);

    expect(liveKit()->participants('lanomat-7'))->toBe([]);
});

it('removes a participant by identity', function () {
    Http::fake(['livekit.test:7880/*' => Http::response([])]);

    liveKit()->removeParticipant('lanomat-7', 'u42');

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/RemoveParticipant')
        && $r->data() === ['room' => 'lanomat-7', 'identity' => 'u42']);
});

it('revokes publishing with the FULL permission object, so the participant can still listen', function () {
    Http::fake(['livekit.test:7880/*' => Http::response([])]);

    liveKit()->setCanPublish('lanomat-7', 'u42', false);

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/UpdateParticipant')
        && $r->data() === [
            'room' => 'lanomat-7',
            'identity' => 'u42',
            'permission' => [
                'canSubscribe' => true,
                'canPublish' => false,
                'canPublishData' => false,
                'canPublishSources' => ['MICROPHONE'],
            ],
        ]);
});

it('reports an unreachable or failing server as unavailable', function () {
    Http::fake(['livekit.test:7880/*' => Http::response('boom', 500)]);

    liveKit()->participants('lanomat-7');
})->throws(WebVoiceUnavailable::class);

it('reports a connection failure as unavailable', function () {
    Http::fake(fn () => throw new ConnectionException('refused'));

    liveKit()->removeParticipant('lanomat-7', 'u1');
})->throws(WebVoiceUnavailable::class);
