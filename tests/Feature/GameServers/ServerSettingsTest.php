<?php

declare(strict_types=1);

use App\Models\User;
use App\Modules\GameServers\Actions\ChangeServerMap;
use App\Modules\GameServers\Actions\DeletePermanentServer;
use App\Modules\GameServers\Actions\GunGameSettings;
use App\Modules\GameServers\Actions\LinkPelicanServer;
use App\Modules\GameServers\Actions\PowerConfiguredServer;
use App\Modules\GameServers\Actions\UpdateServerSettings;
use App\Modules\GameServers\Domain\JoinInfo;
use App\Modules\GameServers\Domain\PelicanEgg;
use App\Modules\GameServers\Domain\PowerAction;
use App\Modules\GameServers\Enums\ServerSource;
use App\Modules\GameServers\Exceptions\GameServerException;
use App\Modules\GameServers\Filament\Pages\ServerSettings;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Support\Cs2\GunGameConfig;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);

const GG_GENERAL = '{
  "_comment.1": "plugin note",
  "MinKillsPerLevel": 2,
  "TurboMode": false,
  "Unknown": {"keep": [1, 2]}
}
';
const GG_WEAPONS = '{
  "_comment.weapons": "order",
  "WeaponOrder": {"1": "glock", "2": "knife"},
  "MultipleKillsPerLevel": {"2": 3}
}
';

beforeEach(function () {
    $this->pelican = fakePelican();
    $this->pelican->eggs['5'] = new PelicanEgg('5', 'CS2', array_fill_keys(['SERVER_NAME', 'SRCDS_MAP', 'GAME_TYPE', 'GAME_MODE', 'MAX_PLAYERS', 'SERVER_PASSWORD', 'HOST_WORKSHOP_MAP'], ''));
    $this->panel = $this->pelican->addServer('7', 'abcd1234', ['SERVER_NAME' => 'Old', 'SRCDS_MAP' => 'de_dust2', 'GAME_TYPE' => '0', 'GAME_MODE' => '1', 'MAX_PLAYERS' => '10', 'SERVER_PASSWORD' => 'oldsecret', 'KEEP_ME' => 'yes']);
    $this->link = ServerLink::factory()->permanent(ServerSource::External)->create([
        'title' => 'CS2 5v5 Competitive',
        'pelican_server_id' => '7',
        'pelican_server_identifier' => 'abcd1234',
        'join_info' => new JoinInfo('203.0.113.5', 27015, 'oldsecret'),
    ]);
    $this->orga = User::factory()->orga()->create();
    $this->admin = User::factory()->admin()->create();
    $this->input = ['server_name' => 'LAN Competitive', 'mode' => 'wingman', 'map' => 'de_inferno', 'workshop_id' => null, 'max_players' => 4, 'password' => '', 'clear_password' => false];
});

it('writes the startup variables, keeps the rest of the environment and leaves the password when blank', function () {
    $result = app(UpdateServerSettings::class)->handle($this->orga, $this->link, $this->input);

    expect($result)->toBe(['skipped' => [], 'restarted' => false]);
    expect($this->pelican->startupUpdates)->toHaveCount(1);
    expect($this->pelican->startupUpdates[0]['variables'])->toMatchArray([
        'SERVER_NAME' => 'LAN Competitive', 'SRCDS_MAP' => 'de_inferno', 'GAME_TYPE' => '0', 'GAME_MODE' => '2', 'MAX_PLAYERS' => '4',
    ])->not->toHaveKey('SERVER_PASSWORD');
    $env = $this->pelican->getServer('7')->meta['container']['environment'];
    expect($env['SERVER_PASSWORD'])->toBe('oldsecret')->and($env['KEEP_ME'])->toBe('yes');
    expect($this->link->fresh()->join_info->password)->toBe('oldsecret');
    expect($this->pelican->powerActions)->toBe([]);
});

it('sets and clears the password and mirrors it into the join info', function () {
    app(UpdateServerSettings::class)->handle($this->orga, $this->link, ['password' => 'new_pass-1'] + $this->input);
    expect($this->pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('new_pass-1')
        ->and($this->link->fresh()->join_info->password)->toBe('new_pass-1');

    app(UpdateServerSettings::class)->handle($this->orga, $this->link->fresh(), ['clear_password' => true] + $this->input);
    expect($this->pelican->getServer('7')->meta['container']['environment']['SERVER_PASSWORD'])->toBe('')
        ->and($this->link->fresh()->join_info->password)->toBeNull();
});

it('restarts after saving on request', function () {
    $result = app(UpdateServerSettings::class)->handle($this->admin, $this->link, $this->input, true);

    expect($result['restarted'])->toBeTrue();
    $this->pelican->assertPowerAction('abcd1234', PowerAction::Restart);
});

it('reports core variables the egg does not declare and does not send them', function () {
    $this->pelican->eggs['5'] = new PelicanEgg('5', 'CS2', ['SERVER_NAME' => '', 'SRCDS_MAP' => '']);

    $result = app(UpdateServerSettings::class)->handle($this->orga, $this->link, $this->input);

    expect($result['skipped'])->toContain('GAME_MODE', 'MAX_PLAYERS')
        ->and(array_keys($this->pelican->startupUpdates[0]['variables']))->toBe(['SERVER_NAME', 'SRCDS_MAP']);
});

it('validates ranges, map format and password rule before calling Pelican', function (array $override) {
    expect(fn () => app(UpdateServerSettings::class)->handle($this->orga, $this->link, $override + $this->input))
        ->toThrow(ValidationException::class);
    expect($this->pelican->startupUpdates)->toBe([]);
})->with([
    'too many players' => [['max_players' => 65]],
    'zero players' => [['max_players' => 0]],
    'unknown map' => [['map' => 'de_evil; quit']],
    'bad workshop id' => [['map' => null, 'workshop_id' => 'abc']],
    'no map at all' => [['map' => null, 'workshop_id' => null]],
    'bad mode' => [['mode' => 'godmode']],
    'password with space' => [['password' => 'no spaces']],
    'password too long' => [['password' => str_repeat('a', 31)]],
]);

it('stores a workshop map in HOST_WORKSHOP_MAP and keeps SRCDS_MAP', function () {
    app(UpdateServerSettings::class)->handle($this->orga, $this->link, ['map' => null, 'workshop_id' => '3070284539'] + $this->input);

    expect($this->pelican->startupUpdates[0]['variables'])->toMatchArray(['HOST_WORKSHOP_MAP' => '3070284539'])->not->toHaveKey('SRCDS_MAP');
});

it('changes the map through the console with fixed templates', function () {
    app(ChangeServerMap::class)->handle($this->orga, $this->link, 'de_mirage', null);
    app(ChangeServerMap::class)->handle($this->orga, $this->link, null, '3070284539');

    expect(array_column($this->pelican->commands, 'command'))->toBe(['changelevel de_mirage', 'host_workshop_map 3070284539']);
    expect(fn () => app(ChangeServerMap::class)->handle($this->orga, $this->link, 'x; quit', null))->toThrow(ValidationException::class);
});

it('powers a linked external server for orga', function () {
    app(PowerConfiguredServer::class)->handle($this->orga, $this->link, PowerAction::Stop);

    $this->pelican->assertPowerAction('abcd1234', PowerAction::Stop);
});

it('forbids participants and helpers on every operation', function (string $role) {
    $user = $role === 'helper' ? User::factory()->helper()->create() : User::factory()->create();

    expect(fn () => app(UpdateServerSettings::class)->handle($user, $this->link, $this->input))->toThrow(AuthorizationException::class);
    expect(fn () => app(ChangeServerMap::class)->handle($user, $this->link, 'de_dust2', null))->toThrow(AuthorizationException::class);
    expect(fn () => app(PowerConfiguredServer::class)->handle($user, $this->link, PowerAction::Start))->toThrow(AuthorizationException::class);
    expect(fn () => app(GunGameSettings::class)->save($user, $this->link, []))->toThrow(AuthorizationException::class);
    expect($this->pelican->startupUpdates)->toBe([])->and($this->pelican->commands)->toBe([])->and($this->pelican->powerActions)->toBe([]);
})->with(['participant' => 'participant', 'helper' => 'helper']);

it('refuses a server without a Pelican link with a clear error', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create();

    expect(fn () => app(UpdateServerSettings::class)->handle($this->orga, $link, $this->input))
        ->toThrow(GameServerException::class);
    expect(fn () => app(PowerConfiguredServer::class)->handle($this->orga, $link, PowerAction::Start))
        ->toThrow(GameServerException::class);
});

it('does not let orga configure a non-permanent server', function () {
    $match = ServerLink::factory()->create(['pelican_server_id' => '7', 'pelican_server_identifier' => 'abcd1234']);

    expect(fn () => app(UpdateServerSettings::class)->handle($this->orga, $match, $this->input))->toThrow(AuthorizationException::class);
});

it('saves the GunGame config merged, restarts the plugin and keeps unknown keys', function () {
    $this->pelican->setFileContents('abcd1234', GunGameConfig::GENERAL_FILE, GG_GENERAL);
    $this->pelican->setFileContents('abcd1234', GunGameConfig::WEAPONS_FILE, GG_WEAPONS);

    $form = app(GunGameSettings::class)->load($this->orga, $this->link);
    expect($form['MinKillsPerLevel'])->toBe(2)->and($form['TurboMode'])->toBe('off')->and($form['weapon_order'])->toBe([['weapon' => 'glock', 'kills' => null], ['weapon' => 'knife', 'kills' => 3]]);

    $reloaded = app(GunGameSettings::class)->save($this->orga, $this->link, [
        'MinKillsPerLevel' => 4, 'TurboMode' => 'on', 'WarmupTimeLength' => null,
        'weapon_order' => [['weapon' => 'ak47', 'kills' => 2], ['weapon' => 'deagle', 'kills' => null], ['weapon' => 'knife', 'kills' => null]],
    ]);

    expect($reloaded)->toBeTrue();
    expect(array_column($this->pelican->commands, 'command'))->toBe(['gg_restart']);
    $general = json_decode($this->pelican->fileContents('abcd1234', GunGameConfig::GENERAL_FILE), true);
    expect($general)->toMatchArray(['_comment.1' => 'plugin note', 'MinKillsPerLevel' => 4, 'TurboMode' => true, 'Unknown' => ['keep' => [1, 2]]])
        ->not->toHaveKey('WarmupTimeLength');
    $weapons = json_decode($this->pelican->fileContents('abcd1234', GunGameConfig::WEAPONS_FILE), true);
    expect($weapons['_comment.weapons'])->toBe('order')
        ->and($weapons['WeaponOrder'])->toBe(['1' => 'ak47', '2' => 'deagle', '3' => 'knife'])
        ->and($weapons['MultipleKillsPerLevel'])->toBe([1 => 2]);
    expect($this->pelican->fileContents('abcd1234', GunGameConfig::GENERAL_FILE))->toContain("\n  \"MinKillsPerLevel\": 4,");
});

it('never overwrites an invalid file and writes nothing', function () {
    $this->pelican->setFileContents('abcd1234', GunGameConfig::GENERAL_FILE, 'not json');
    $this->pelican->setFileContents('abcd1234', GunGameConfig::WEAPONS_FILE, GG_WEAPONS);

    expect(fn () => app(GunGameSettings::class)->save($this->orga, $this->link, ['weapon_order' => [['weapon' => 'ak47', 'kills' => null]]]))
        ->toThrow(GameServerException::class);
    expect($this->pelican->writtenFiles)->toBe([]);
});

it('rejects an unknown weapon and an out of range value', function () {
    $this->pelican->setFileContents('abcd1234', GunGameConfig::GENERAL_FILE, GG_GENERAL);
    $this->pelican->setFileContents('abcd1234', GunGameConfig::WEAPONS_FILE, GG_WEAPONS);

    expect(fn () => app(GunGameSettings::class)->save($this->orga, $this->link, ['weapon_order' => [['weapon' => 'bazooka', 'kills' => null]]]))->toThrow(ValidationException::class);
    expect(fn () => app(GunGameSettings::class)->save($this->orga, $this->link, ['RespawnByPlugin' => 9, 'weapon_order' => [['weapon' => 'ak47', 'kills' => null]]]))->toThrow(ValidationException::class);
    expect($this->pelican->writtenFiles)->toBe([]);
});

it('adopts an external server by identifier, uuid or numeric id and refuses the rest', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create();

    $server = app(LinkPelicanServer::class)->handle($link, 'ABCD1234', true);

    expect($server->id)->toBe('7');
    $link->refresh();
    expect($link->pelican_server_id)->toBe('7')->and($link->pelican_server_identifier)->toBe('abcd1234')
        ->and($link->hasPelicanLink())->toBeTrue()->and($link->source)->toBe(ServerSource::External)
        ->and($link->preset_key)->toBe(GunGameConfig::PRESET_KEY);

    $other = ServerLink::factory()->permanent(ServerSource::External)->create();
    app(LinkPelicanServer::class)->handle($other, 'abcd1234-0000-4000-8000-000000000000');
    expect($other->fresh()->pelican_server_id)->toBe('7');

    expect(fn () => app(LinkPelicanServer::class)->handle(ServerLink::factory()->permanent(ServerSource::External)->create(), 'nope'))->toThrow(GameServerException::class);
    expect(fn () => app(LinkPelicanServer::class)->handle(ServerLink::factory()->create(), '7'))->toThrow(GameServerException::class);
});

it('links through the artisan command by title and by id', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create(['title' => 'CS2 GunGame']);

    $this->artisan('gameservers:link-pelican', ['server' => 'CS2 GunGame', 'pelican' => 'abcd1234', '--gungame' => true])->assertSuccessful();
    expect($link->fresh()->hasPelicanLink())->toBeTrue();

    $this->artisan('gameservers:link-pelican', ['server' => 'No such', 'pelican' => 'abcd1234'])->assertFailed();
    $this->artisan('gameservers:link-pelican', ['server' => (string) $link->id, 'pelican' => 'unknown0'])->assertFailed();
});

it('keeps an adopted server external: removing the entry never deletes the panel server', function () {
    app(DeletePermanentServer::class)->handle($this->admin, $this->link);

    expect($this->pelican->deleted)->toBe([]);
});

it('lets orga open the page but not the permanent server resource', function () {
    $this->actingAs($this->orga)->get('/admin/server-settings')->assertOk();
    $this->actingAs($this->orga)->get('/admin/permanent-servers')->assertForbidden();
    $this->actingAs(User::factory()->helper()->create())->get('/admin/server-settings')->assertForbidden();
    $this->actingAs(User::factory()->create())->get('/admin/server-settings')->assertForbidden();
});

it('shows the current values without the password and saves through the page', function () {
    $this->actingAs($this->orga);

    $component = Livewire::test(ServerSettings::class, ['server' => $this->link->id])
        ->assertSet('settingsData.server_name', 'Old')
        ->assertSet('settingsData.password', null)
        ->assertDontSee('oldsecret');

    $component->fillForm($this->input, 'settingsForm')->call('saveSettingsAndRestart')->assertNotified(__('gameservers.settings.general.saved_restarted'));

    expect($this->pelican->startupUpdates[0]['variables']['SERVER_NAME'])->toBe('LAN Competitive');
    $this->pelican->assertPowerAction('abcd1234', PowerAction::Restart);
});

it('shows a clear message for a server without a Pelican link', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create(['title' => 'Unlinked']);
    $this->actingAs($this->orga);

    Livewire::test(ServerSettings::class, ['server' => $link->id])->assertSee(__('gameservers.settings.not_linked'));
});

it('shows the GunGame form only for a GunGame server', function () {
    $this->pelican->setFileContents('abcd1234', GunGameConfig::GENERAL_FILE, GG_GENERAL);
    $this->pelican->setFileContents('abcd1234', GunGameConfig::WEAPONS_FILE, GG_WEAPONS);
    $this->actingAs($this->orga);

    Livewire::test(ServerSettings::class, ['server' => $this->link->id])->assertDontSee(__('gameservers.settings.gungame.heading'));

    $this->link->update(['preset_key' => GunGameConfig::PRESET_KEY]);
    Livewire::test(ServerSettings::class, ['server' => $this->link->id])->assertSee(__('gameservers.settings.gungame.heading'));
});

it('only lets an admin see the link action', function () {
    $link = ServerLink::factory()->permanent(ServerSource::External)->create();

    $this->actingAs($this->orga);
    Livewire::test(ServerSettings::class, ['server' => $link->id])->assertActionHidden('link');

    $this->actingAs($this->admin);
    Livewire::test(ServerSettings::class, ['server' => $link->id])
        ->callAction('link', ['reference' => 'abcd1234', 'gungame' => false])
        ->assertNotified(__('gameservers.settings.link.done'));
    expect($link->fresh()->hasPelicanLink())->toBeTrue();
});
