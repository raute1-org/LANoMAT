<?php

declare(strict_types=1);

namespace App\Modules\WebVoice\NativeClient\Http;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Modules\WebVoice\NativeClient\Actions\DecideDeviceAuthorization;
use App\Modules\WebVoice\NativeClient\Actions\ResolveUserCode;
use App\Modules\WebVoice\NativeClient\Actions\RevokeVoiceClientToken;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Support\DeviceCodes;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** Participant side of the pairing: the approval page and the "Verbundene Geräte" list. */
class ConnectController extends Controller
{
    public function show(Request $request, ResolveUserCode $resolve): Response
    {
        $user = $this->user($request);
        $input = (string) $request->query('code', '');
        $row = $input === '' ? null : $resolve->handle($user, $input);
        $normalized = DeviceCodes::normalize($input);

        return Inertia::render('WebVoice/Connect', [
            // empty: no code yet | pending: a live request to decide | invalid: unknown, expired or used
            'state' => $input === '' ? 'empty' : ($row === null ? 'invalid' : 'pending'),
            'code' => $row !== null && $normalized !== null ? DeviceCodes::format($normalized) : null,
            'deviceName' => $row?->device_name,
            'devices' => $this->devices($user),
            'labels' => trans('web_voice.connect'),
        ]);
    }

    public function decide(Request $request, DecideDeviceAuthorization $decide): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'decision' => ['required', 'in:approve,deny'],
        ]);

        $approve = $data['decision'] === 'approve';

        if (! $decide->handle($this->user($request), $data['code'], $approve)) {
            Inertia::flash('toast', ['type' => 'error', 'message' => __('web_voice.connect.invalid')]);

            return to_route('webvoice.connect', ['code' => $data['code']]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __($approve ? 'web_voice.connect.approved' : 'web_voice.connect.denied')]);

        return to_route('webvoice.connect');
    }

    public function revoke(Request $request, VoiceClientToken $device, RevokeVoiceClientToken $revoke): RedirectResponse
    {
        $revoke->handle($this->user($request), $device);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('web_voice.connect.revoked')]);

        return to_route('webvoice.connect');
    }

    /** @return list<array{id: int, name: string, created_at: string|null, last_used_at: string|null}> */
    private function devices(User $user): array
    {
        $devices = [];

        foreach (VoiceClientToken::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->latest('id')
            ->get() as $token) {
            $devices[] = [
                'id' => $token->id,
                'name' => $token->device_name,
                'created_at' => $token->created_at?->toIso8601String(),
                'last_used_at' => $token->last_used_at?->toIso8601String(),
            ];
        }

        return $devices;
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
