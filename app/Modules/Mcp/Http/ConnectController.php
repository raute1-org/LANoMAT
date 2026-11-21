<?php

declare(strict_types=1);

namespace App\Modules\Mcp\Http;

use App\Concerns\ResolvesAuthenticatedUser;
use App\Http\Controllers\Controller;
use App\Modules\Mcp\Actions\DecideDeviceAuthorization;
use App\Modules\Mcp\Actions\ResolveUserCode;
use App\Modules\Mcp\Actions\RevokeMcpToken;
use App\Modules\Mcp\Models\McpToken;
use App\Modules\Mcp\Support\McpCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Browser end of the pairing flow: a logged-in user types the code the launcher
 * (or the agent) printed, approves it, and sees every connected device afterwards.
 */
class ConnectController extends Controller
{
    use ResolvesAuthenticatedUser;

    public function show(Request $request, ResolveUserCode $resolve): Response
    {
        $user = $this->authUser($request);
        $input = mb_substr(trim($request->string('code')->toString()), 0, 32);
        $pending = $input === '' ? null : $resolve->handle($user, $input);

        return Inertia::render('Mcp/Connect', [
            'state' => $input === '' ? 'form' : ($pending === null ? 'invalid' : 'pending'),
            'code' => $input === '' ? null : ($pending === null ? $input : McpCodes::format((string) McpCodes::normalize($input))),
            'deviceName' => $pending?->device_name,
            'devices' => McpToken::query()
                ->where('user_id', $user->getKey())
                ->whereNull('revoked_at')
                ->where('expires_at', '>', now())
                ->orderByDesc('last_used_at')
                ->orderByDesc('id')
                ->get()
                ->map(fn (McpToken $token): array => [
                    'id' => $token->id,
                    'name' => $token->name,
                    'createdAt' => $token->created_at?->toIso8601String(),
                    'lastUsedAt' => $token->last_used_at?->toIso8601String(),
                ])
                ->all(),
            'labels' => trans('mcp.connect'),
        ]);
    }

    public function decide(Request $request, DecideDeviceAuthorization $decide): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'decision' => ['required', 'in:approve,deny'],
        ]);

        $ok = $decide->handle($this->authUser($request), $data['code'], $data['decision'] === 'approve');

        Inertia::flash('toast', [
            'type' => $ok ? 'success' : 'error',
            'message' => trans($ok ? 'mcp.connect.'.($data['decision'] === 'approve' ? 'approved' : 'denied') : 'mcp.connect.invalid'),
        ]);

        return to_route('mcp.connect');
    }

    public function revoke(Request $request, McpToken $device, RevokeMcpToken $revoke): RedirectResponse
    {
        $revoke->handle($this->authUser($request), $device);

        Inertia::flash('toast', ['type' => 'success', 'message' => trans('mcp.connect.revoked')]);

        return to_route('mcp.connect');
    }
}
