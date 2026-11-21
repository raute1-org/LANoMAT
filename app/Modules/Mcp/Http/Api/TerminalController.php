<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http\Api;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\GameRecipes\Actions\RecordTerminalHeartbeat;
use App\Modules\GameRecipes\Enums\TerminalState;
use App\Modules\GameRecipes\Models\LauncherTerminal;
use App\Modules\Mcp\Models\McpToken;
use App\Support\SafeText;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The launcher fleet registry: the arcade-cabinet / gaming-PC terminals of the
 * LAN report in here and the orga monitor reads the fleet from here.
 *
 * Heartbeat identity is the bearer token, never a client-supplied id, so a
 * paired launcher can only ever write its own row. The list is orga-only
 * (LauncherTerminalPolicy) and carries nothing beyond the terminal itself and
 * the user who paired it.
 */
class TerminalController extends Controller
{
    use ResolvesAuthenticatedUser;

    /**
     * `POST /api/v1/terminals/heartbeat` — upsert this token's terminal.
     *
     * Validation failures are a 422 with field errors (the launcher shows them
     * as its own connection problem); `last_seen_at` and `ip` are server facts.
     */
    public function heartbeat(Request $request, RecordTerminalHeartbeat $record): JsonResponse
    {
        $token = $request->attributes->get('mcp_token');

        abort_unless($token instanceof McpToken, 401);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60'],
            'os' => ['nullable', 'string', Rule::in(['windows', 'linux', 'macos'])],
            'arch' => ['nullable', 'string', 'max:16', 'regex:/^[A-Za-z0-9_.-]+$/'],
            'version' => ['nullable', 'string', 'max:32'],
            'state' => ['nullable', Rule::enum(TerminalState::class)],
            'current_game_slug' => ['nullable', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'installed' => ['nullable', 'array', 'max:500'],
            'installed.*' => ['string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'gamepad' => ['nullable', 'boolean'],
        ]);

        $name = SafeText::line($data['name'], 60);

        if ($name === null) {
            throw ValidationException::withMessages([
                'name' => __('validation.required', ['attribute' => 'name']),
            ]);
        }

        $data['name'] = $name;

        // Slugs are matched against recipe slugs (lowercase): normalise once
        // here so the monitor never shows "UT2004" and "ut2004" as two games.
        if (isset($data['installed'])) {
            /** @var list<string> $installed */
            $installed = $data['installed'];

            $data['installed'] = collect($installed)
                ->map(static fn (string $slug): string => mb_strtolower(trim($slug)))
                ->unique()
                ->values()
                ->all();
        }

        $terminal = $record->handle($token, $data, $request->ip());

        return response()->json([
            'ok' => true,
            'terminal_id' => $terminal->id,
            'server_time' => now()->toIso8601String(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** `GET /api/v1/terminals` — the orga monitor's fleet list. */
    public function index(Request $request): JsonResponse
    {
        $user = $this->authUser($request);

        // The bearer guard resolves the user on the request, not in Auth, so
        // the policy must be asked for that user explicitly.
        Gate::forUser($user)->authorize('viewAny', LauncherTerminal::class);

        $terminals = LauncherTerminal::query()
            ->with(['token.user'])
            ->orderByDesc('last_seen_at')
            ->orderBy('name')
            ->get()
            // Online first, then the rest by name: the fleet list reads like a
            // rack, the lit cabinets up top.
            ->sortBy([
                fn (LauncherTerminal $a, LauncherTerminal $b): int => $b->isOnline() <=> $a->isOnline(),
                fn (LauncherTerminal $a, LauncherTerminal $b): int => $a->name <=> $b->name,
            ])
            ->values();

        return response()->json([
            'data' => $terminals->map(fn (LauncherTerminal $terminal): array => $this->project($terminal))->all(),
            'heartbeat_seconds' => LauncherTerminal::HEARTBEAT_SECONDS,
            'offline_after_seconds' => LauncherTerminal::OFFLINE_AFTER_SECONDS,
            'server_time' => now()->toIso8601String(),
        ], 200, ['Cache-Control' => 'no-store, private']);
    }

    /** @return array<string, mixed> */
    private function project(LauncherTerminal $terminal): array
    {
        $user = $terminal->token?->user;
        $installed = $terminal->installedSlugs();

        return [
            'id' => $terminal->id,
            'name' => $terminal->name,
            'state' => $terminal->derivedState()->value,
            'online' => $terminal->isOnline(),
            'os' => $terminal->os,
            'arch' => $terminal->arch,
            'version' => $terminal->version,
            'current_game_slug' => $terminal->current_game_slug,
            'installed' => $installed,
            'installed_count' => count($installed),
            'gamepad' => $terminal->gamepad,
            'last_seen_at' => $terminal->last_seen_at?->toIso8601String(),
            'ip' => $terminal->ip,
            'paired_user' => $user === null ? null : [
                'id' => $user->id,
                'name' => $user->name,
            ],
            'token_name' => $terminal->token?->name,
        ];
    }
}
