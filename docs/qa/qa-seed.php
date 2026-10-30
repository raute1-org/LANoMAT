// QA seed script (sanitised: placeholder data only, nothing real).
//   Seed:     php artisan tinker --execute="$(cat docs/qa/qa-seed.php)"
//   Cleanup:  QA_SEED_CLEANUP=1 php artisan tinker --execute="$(cat docs/qa/qa-seed.php)"
// Idempotent. LOCAL ONLY: refuses unless APP_ENV=local AND the active database name starts with "lanomat_qa"
// (so it can never touch the dev or production database). Everything is prefixed QA- / qa- / example.test.
// The reliable full cleanup is dropping the throwaway database; the cleanup mode removes the seeded rows best effort.
use App\Enums\Role;
use App\Models\User;
use App\Modules\Events\Enums\EventStatus;
use App\Modules\Events\Models\Event;
use App\Modules\Games\Models\Game;
use App\Modules\Seating\Actions\GenerateSeatGrid;
use App\Modules\Tournaments\Enums\TournamentFormat;
use App\Modules\Tournaments\Models\Tournament;
use Illuminate\Support\Facades\Hash;

if (! app()->environment('local')) { echo "REFUSED: not local\n"; return; }
$qaDb = (string) config('database.connections.'.config('database.default').'.database');
if (! str_starts_with($qaDb, 'lanomat_qa')) { echo "REFUSED: database is not a throwaway lanomat_qa* database\n"; return; }

if (getenv('QA_SEED_CLEANUP')) {
    try {
        \Illuminate\Support\Facades\DB::transaction(function () {
            Tournament::where('name', 'like', 'QA-%')->each(fn ($t) => $t->delete());
            Event::where('slug', 'like', 'qa-%')->each(fn ($e) => $e->delete());
            User::where('email', 'like', 'qa-%@example.test')->each(fn ($u) => $u->delete());
        });
        echo "QA cleanup done\n";
    } catch (\Throwable $e) {
        echo 'QA cleanup failed (drop the throwaway database instead): '.$e->getMessage()."\n";
    }
    return;
}

// --- users (password for all: "password"; login via POST /login {email,password}) ---
$mk = function (string $key, string $name, Role $role, string $discordId) {
    $u = User::firstOrNew(['email' => "qa-{$key}@example.test"]);
    $u->name = $name; $u->discord_id = $discordId; $u->password = Hash::make('password');
    $u->email_verified_at = now();
    $u->forceFill(['role' => $role])->save();
    return $u;
};
$mk('admin', 'QA-Admin', Role::Admin, '100000000000000001');
$mk('orga', 'QA-Orga', Role::Orga, '100000000000000002');
$mk('helper', 'QA-Helper', Role::Helper, '100000000000000003');
foreach (range(1, 10) as $i) { $mk("p{$i}", "QA-P{$i}", Role::Participant, sprintf('1000000000000001%02d', $i)); }

// --- events (one per lifecycle state) ---
$ev = function (string $slug, string $name, EventStatus $st, int $startOffsetHours, int $max) {
    $e = Event::firstWhere('slug', $slug);
    if (! $e) {
        $e = new Event();
        $e->forceFill(['name' => $name, 'slug' => $slug, 'status' => $st, 'location' => 'QA-Venue',
            'starts_at' => now()->addHours($startOffsetHours), 'ends_at' => now()->addHours($startOffsetHours + 72),
            'max_participants' => $max, 'settings' => ['tickets' => ['standard', 'vip']]])->save();
    }
    return $e;
};
$draft = $ev('qa-draft', 'QA-Event Draft', EventStatus::Draft, 500, 20);
$ann = $ev('qa-announced', 'QA-Event Announced', EventStatus::Announced, 400, 20);
$reg = $ev('qa-reg', 'QA-Event Registration', EventStatus::Registration, 48, 20);
$small = $ev('qa-small', 'QA-Event Small (max 2)', EventStatus::Registration, 48, 2);
$near = $ev('qa-near', 'QA-Event Starts In 6h', EventStatus::Registration, 6, 20);
$live = $ev('qa-live', 'QA-Event Live', EventStatus::Live, -2, 20);
$fin = $ev('qa-finished', 'QA-Event Finished', EventStatus::Finished, -200, 20);

if (\App\Modules\Seating\Models\Seat::where("event_id", $live->id)->count() === 0) { app(GenerateSeatGrid::class)->handle($live, 3, 4, 'QA'); }
if (\App\Modules\Seating\Models\Seat::where("event_id", $reg->id)->count() === 0) { app(GenerateSeatGrid::class)->handle($reg, 2, 4, 'R'); }

// --- tournaments on the live event (status enrollment unless noted) ---
$game = Game::firstWhere('slug', 'minecraft') ?? Game::first();
$tn = function (string $name, TournamentFormat $f, string $status = 'enrollment', int $teamSize = 1) use ($live, $game) {
    $t = Tournament::firstWhere('name', $name);
    if (! $t) {
        $t = new Tournament();
        $t->forceFill(['event_id' => $live->id, 'game_id' => $game?->id, 'name' => $name, 'format' => $f,
            'status' => $status, 'team_size' => $teamSize, 'max_entries' => 16, 'starts_at' => now()->addDay(), 'settings' => []])->save();
    }
    return $t;
};
$tn('QA-SE Solo', TournamentFormat::SingleElimination);
$tn('QA-DE Solo', TournamentFormat::DoubleElimination);
$tn('QA-RR Solo', TournamentFormat::RoundRobin);
$tn('QA-SE Draft (no enrollment)', TournamentFormat::SingleElimination, 'draft');
echo "QA seed done: users=".User::where('email', 'like', 'qa-%')->count().", events=".Event::where('slug', 'like', 'qa-%')->count().", tournaments=".Tournament::where('name', 'like', 'QA-%')->count()."\n";
