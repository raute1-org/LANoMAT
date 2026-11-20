<?php

declare(strict_types=1);

use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Support\Cs2\Cs2Settings;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use Illuminate\Validation\ValidationException;

it('keeps unknown keys, comments, empty objects and the file own flag type when merging', function () {
    $raw = json_encode([
        '_comment.general' => 'keep me',
        'MinKillsPerLevel' => 2,
        'TurboMode' => 0,
        'WarmupEnabled' => true,
        'SomethingNew' => ['a' => 1],
        'Empty' => new stdClass,
    ], JSON_PRETTY_PRINT);

    $doc = GunGameConfig::mergeGeneral(GunGameConfig::decode($raw, 'f'), [
        'MinKillsPerLevel' => 3, 'TurboMode' => 'on', 'WarmupEnabled' => 'off', 'EndGameDelay' => null,
    ]);
    $out = GunGameConfig::encode($doc);

    expect($out)->toContain('  "_comment.general": "keep me"')
        ->toContain('"MinKillsPerLevel": 3')
        ->toContain('"TurboMode": 1')
        ->toContain('"WarmupEnabled": false')
        ->toContain('"Empty": {}')
        ->not->toContain('EndGameDelay')
        ->toEndWith("}\n");
    expect(json_decode($out, true)['SomethingNew'])->toBe(['a' => 1]);
});

it('writes the weapon order with numbered keys and renumbers from one', function () {
    $weapons = GunGameConfig::decode('{"_comment.w":"x","WeaponOrder":{"1":"glock","5":"knife"},"Other":true}', 'w');

    $out = GunGameConfig::encode(GunGameConfig::mergeWeapons($weapons, ['weapon_order' => [
        ['weapon' => 'ak47', 'kills' => 2], ['weapon' => 'deagle', 'kills' => null], ['weapon' => 'knife', 'kills' => null],
    ]]));
    $decoded = json_decode($out, true);

    expect($decoded['WeaponOrder'])->toBe(['1' => 'ak47', '2' => 'deagle', '3' => 'knife'])
        ->and($decoded['MultipleKillsPerLevel'])->toBe([1 => 2])
        ->and($decoded['_comment.w'])->toBe('x')
        ->and($decoded['Other'])->toBeTrue()
        ->and($out)->toContain("  \"WeaponOrder\": {\n    \"1\": \"ak47\"");
});

it('refuses a file that is not a JSON object', function () {
    GunGameConfig::decode('[1,2]', 'gungame.json');
})->throws(GameServerException::class);

it('validates ranges and the weapon allowlist', function () {
    GunGameConfig::validate(['weapon_order' => [['weapon' => 'rocket_launcher', 'kills' => null]], 'MinKillsPerLevel' => 99]);
})->throws(ValidationException::class);

it('builds only digits-only workshop commands and allowlisted maps', function () {
    expect(Cs2Settings::mapCommand('de_dust2', null))->toBe('changelevel de_dust2')
        ->and(Cs2Settings::mapCommand(null, '3070284539'))->toBe('host_workshop_map 3070284539');

    expect(fn () => Cs2Settings::validate(['server_name' => 'x', 'mode' => 'wingman', 'workshop_id' => '12; quit', 'max_players' => 10]))
        ->toThrow(ValidationException::class);
    expect(fn () => Cs2Settings::validate(['server_name' => 'x"; quit', 'mode' => 'wingman', 'map' => 'de_dust2', 'max_players' => 10]))
        ->toThrow(ValidationException::class);
    expect(fn () => Cs2Settings::validate(['server_name' => 'x', 'mode' => 'wingman', 'map' => 'de_dust2', 'max_players' => 65]))
        ->toThrow(ValidationException::class);
    expect(fn () => Cs2Settings::validate(['server_name' => 'x', 'mode' => 'wingman', 'map' => 'de_dust2', 'max_players' => 10, 'password' => 'bad pass']))
        ->toThrow(ValidationException::class);
});

it('maps modes to game_type and game_mode', function () {
    $s = Cs2Settings::validate(['server_name' => 'W 1', 'mode' => 'wingman', 'map' => 'de_inferno', 'max_players' => 4, 'password' => '']);

    expect($s->variables())->toMatchArray(['GAME_TYPE' => '0', 'GAME_MODE' => '2', 'SRCDS_MAP' => 'de_inferno', 'MAX_PLAYERS' => '4', 'HOST_WORKSHOP_MAP' => ''])
        ->not->toHaveKey('SERVER_PASSWORD');
});
