<?php

use App\Models\User;
use App\Modules\Assistant\Tools\AssistantTool;
use App\Modules\Tournaments\Exceptions\TournamentException;
use App\Modules\Tournaments\Models\Tournament;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

/** Test double whose run() throws whatever the test hands in. */
final class ThrowingAssistantTool extends AssistantTool
{
    public static ?Closure $body = null;

    /** @var list<Module> */
    public static array $modules = [];

    public static function toolName(): string
    {
        return 'throwing_tool';
    }

    public static function requiredModules(): array
    {
        return self::$modules;
    }

    public function description(): string
    {
        return 'test';
    }

    protected function run(Request $request): array|string
    {
        return (self::$body)($request);
    }
}

function runThrowingTool(Closure $body, ?User $actor = null): string
{
    ThrowingAssistantTool::$body = $body;

    return (string) (new ThrowingAssistantTool($actor ?? User::factory()->orga()->create()))->handle(new Request([]));
}

afterEach(function () {
    ThrowingAssistantTool::$modules = [];
});

it('maps each exception class to a readable answer', function () {
    expect(runThrowingTool(fn () => throw new AuthorizationException))->toStartWith('Forbidden')
        ->and(runThrowingTool(fn () => throw new ModelNotFoundException))->toBe('Not found.')
        ->and(runThrowingTool(fn () => throw new DomainException('Busy right now.')))->toBe('Busy right now.')
        ->and(runThrowingTool(function () {
            Validator::make(['name' => ''], ['name' => 'required'])->validate();
        }))->toStartWith('name: ');
});

it('uses the translated message of a module exception', function () {
    expect(runThrowingTool(fn () => throw TournamentException::notDraft()))
        ->toBe(__('tournaments.errors.not_draft'));
});

it('never echoes an unexpected exception, only a reference', function () {
    $out = runThrowingTool(fn () => throw new RuntimeException('SQLSTATE secret path /var/www'));

    expect($out)->toContain('ref ')->not->toContain('SQLSTATE')->not->toContain('/var/www');
});

it('refuses a participant', function () {
    expect(runThrowingTool(fn () => ['ok' => true], User::factory()->create()))->toStartWith('Forbidden');
});

it('refuses while a wrapped module is off', function () {
    app()->instance(Modules::class, new Modules('core'));
    ThrowingAssistantTool::$modules = [Module::Assistant];

    expect(runThrowingTool(fn () => ['ok' => true]))->toContain('not enabled');
});

it('answers an invented id with Not found and writes nothing', function () {
    $before = DB::table('tournaments')->count();

    $out = runThrowingTool(fn () => Tournament::query()->findOrFail(987654));

    expect($out)->toBe('Not found.')->and(DB::table('tournaments')->count())->toBe($before);
});

it('encodes arrays as JSON and truncates oversized results', function () {
    expect(runThrowingTool(fn () => ['a' => 'ä']))->toBe('{"a":"ä"}')
        ->and(mb_strlen(runThrowingTool(fn () => str_repeat('x', 50000))))->toBeLessThan(12100);
});
