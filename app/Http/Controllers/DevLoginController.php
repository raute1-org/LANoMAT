<?php

namespace App\Http\Controllers;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Local-only demo login. Lets someone peek into a local clone without a
 * Discord app (the only real login path stays Discord OAuth). Registered in
 * every environment but guarded to 404 unless APP_ENV=local, so it is never
 * an attack surface off a dev machine.
 */
class DevLoginController extends Controller
{
    /** @var array<string, array{email: string, name: string, role: Role}> */
    private const DEMO_USERS = [
        'participant' => ['email' => 'demo-participant@lanomat.local', 'name' => 'Demo-Teilnehmer', 'role' => Role::Participant],
        'orga' => ['email' => 'demo-orga@lanomat.local', 'name' => 'Demo-Orga', 'role' => Role::Orga],
    ];

    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(app()->environment('local'), 404);
        abort_unless(array_key_exists($role, self::DEMO_USERS), 404);

        $spec = self::DEMO_USERS[$role];

        $user = User::firstOrCreate(
            ['email' => $spec['email']],
            ['name' => $spec['name'], 'password' => Hash::make(Str::random(40))],
        );

        // `role` is a privilege field (not fillable) — set it explicitly and
        // idempotently so an existing demo user keeps the intended role.
        if ($user->role !== $spec['role']) {
            $user->forceFill(['role' => $spec['role']])->save();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect('/');
    }
}
