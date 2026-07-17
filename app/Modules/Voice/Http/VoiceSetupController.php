<?php

declare(strict_types=1);

namespace App\Modules\Voice\Http;

use App\Http\Controllers\Controller;
use App\Modules\Voice\Domain\VoiceClientPlatform;
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\Models\VoiceClientInstaller;
use App\Modules\Voice\Support\VoiceJoinLink;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoiceSetupController extends Controller
{
    use AuthorizesRequests;

    /**
     * Generic root/lobby channel name for the one-click connect link — there
     * is no per-event or per-team context on this page (it is reached
     * outside any specific event/match), so a stable, provider-agnostic
     * "Lobby" is used rather than an event/LAN name that would need to be
     * threaded through here.
     */
    private const LOBBY_CHANNEL = 'Lobby';

    public function index(Request $request): Response
    {
        $providers = collect(VoiceProvider::active())
            ->map(fn (VoiceProvider $provider): array => $this->providerDto($provider))
            ->all();

        return Inertia::render('Voice/Setup', [
            'providers' => $providers,
            'labels' => trans('voice.setup'),
        ]);
    }

    public function download(Request $request, VoiceClientInstaller $installer): StreamedResponse
    {
        $this->authorize('download', $installer);

        return Storage::disk('local')->download($installer->path, $installer->original_name);
    }

    /**
     * @return array<string, mixed>
     */
    private function providerDto(VoiceProvider $provider): array
    {
        return [
            'provider' => $provider->value,
            'label' => $provider->label(),
            'host' => (string) config("services.{$provider->value}.host"),
            'port' => (string) config("services.{$provider->value}.port"),
            'joinLink' => VoiceJoinLink::for($provider, self::LOBBY_CHANNEL),
            'installers' => $this->currentInstallers($provider),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function currentInstallers(VoiceProvider $provider): array
    {
        $installers = VoiceClientInstaller::query()
            ->where('provider', $provider->value)
            ->where('is_current', true)
            ->get()
            ->keyBy(fn (VoiceClientInstaller $installer): string => $installer->platform->value);

        return collect(VoiceClientPlatform::cases())
            ->map(function (VoiceClientPlatform $platform) use ($installers): ?array {
                $installer = $installers->get($platform->value);

                if (! $installer instanceof VoiceClientInstaller) {
                    return null;
                }

                return [
                    'id' => $installer->id,
                    'platform' => $platform->value,
                    'platformLabel' => $platform->label(),
                    'version' => $installer->version,
                    'originalName' => $installer->original_name,
                ];
            })
            ->filter()
            ->values()
            ->all();
    }
}
