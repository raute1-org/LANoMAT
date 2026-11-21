<?php

declare(strict_types=1);

namespace App\Modules\Lighting\Filament\Pages;

use App\Models\User;
use App\Modules\Lighting\Actions\BlackoutLights;
use App\Modules\Lighting\Actions\ControlGameMode;
use App\Modules\Lighting\Actions\PlayScene;
use App\Modules\Lighting\Actions\SetLightPattern;
use App\Modules\Lighting\Actions\SetLights;
use App\Modules\Lighting\Actions\SetStrobePermission;
use App\Modules\Lighting\Contracts\LightingClient;
use App\Modules\Lighting\Exceptions\LightingRateLimited;
use App\Modules\Lighting\Models\LightingScene;
use App\Modules\Lighting\Models\LightingSetting;
use App\Modules\Lighting\Support\Fixture;
use App\Modules\Lighting\Support\FixtureReport;
use App\Modules\Lighting\Support\GameStatus;
use App\Modules\Lighting\Support\LightingResult;
use App\Modules\Lighting\Support\LightState;
use App\Modules\Lighting\Support\Topology;
use App\Modules\Lighting\Support\TopologyResolver;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Database\Eloquent\Collection;
use InvalidArgumentException;
use UnitEnum;

/**
 * Orga control of the room lights. The shown state always comes from the
 * bridge on render; browser values only travel into the Actions, which
 * authorize, rate-limit, validate and clamp them. Strobe is locked unless
 * allowed (see SafeLightingClient).
 */
class LightControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLightBulb;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?int $navigationSort = 71;

    protected static ?string $slug = 'licht';

    protected string $view = 'filament.pages.light-control';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('lighting.control') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('lighting.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('lighting.title');
    }

    public function applyColor(mixed $target, mixed $hex, mixed $brightness): void
    {
        $this->guarded(function () use ($target, $hex, $brightness): LightingResult {
            if (! is_string($hex)) {
                throw new InvalidArgumentException('hex must be a string');
            }

            return app(SetLights::class)($this->actor(), $this->target($target), LightState::make(
                $hex,
                $this->number($brightness),
            ));
        });
    }

    public function applyWhite(mixed $target, mixed $temperature, mixed $whiteLevel): void
    {
        $this->guarded(fn () => app(SetLights::class)($this->actor(), $this->target($target), LightState::make(
            temperature: $this->number($temperature),
            whiteLevel: $this->number($whiteLevel),
        )));
    }

    public function applyStrobe(mixed $target, mixed $strobe): void
    {
        $this->guarded(fn () => app(SetLights::class)($this->actor(), $this->target($target), LightState::make(
            strobe: $this->number($strobe),
        )));
    }

    public function applyPattern(mixed $a, mixed $b, mixed $brightness): void
    {
        $this->guarded(function () use ($a, $b, $brightness): LightingResult {
            if (! is_string($a) || ! is_string($b)) {
                throw new InvalidArgumentException('colours must be strings');
            }

            return app(SetLightPattern::class)($this->actor(), $a, $b, $this->number($brightness) ?? 1.0);
        });
    }

    public function blackout(): void
    {
        $this->guarded(fn () => app(BlackoutLights::class)($this->actor()));
    }

    public function startGame(mixed $mode = null): void
    {
        $this->guarded(fn () => app(ControlGameMode::class)->start(
            $this->actor(),
            mode: is_string($mode) && $mode !== '' ? $mode : null,
        ));
    }

    public function cancelGame(): void
    {
        $this->guarded(fn () => app(ControlGameMode::class)->cancel($this->actor()));
    }

    public function playScene(mixed $id): void
    {
        $this->guarded(function () use ($id): LightingResult {
            $sceneId = filter_var($id, FILTER_VALIDATE_INT);

            if ($sceneId === false) {
                throw new InvalidArgumentException('scene id must be an integer');
            }

            return app(PlayScene::class)($this->actor(), LightingScene::query()->findOrFail($sceneId));
        });
    }

    public function toggleStrobePermission(): void
    {
        $allowed = ! LightingSetting::strobeAllowed();

        app(SetStrobePermission::class)($this->actor(), $allowed);

        Notification::make()->success()->title(__($allowed ? 'lighting.strobe.enabled' : 'lighting.strobe.disabled'))->send();
    }

    /**
     * @return array{report: FixtureReport, game: GameStatus, topology: Topology, strobeAllowed: bool, maxStrobe: int, scenes: Collection<int, LightingScene>, enabled: bool, configured: bool, pollSeconds: int}
     */
    protected function getViewData(): array
    {
        $report = app(LightingClient::class)->fixtures();

        return [
            'report' => $report,
            'game' => $report->reachable ? app(LightingClient::class)->gameStatus() : GameStatus::unreachable(),
            // An unreachable bridge costs one timeout per poll, not two.
            'topology' => $report->reachable ? app(TopologyResolver::class)->resolve() : new Topology,
            'strobeAllowed' => LightingSetting::strobeAllowed(),
            'maxStrobe' => (int) config('lighting.max_strobe'),
            'scenes' => LightingScene::query()->orderBy('name')->get(),
            'enabled' => (bool) config('services.lighting.enabled'),
            'configured' => filled(config('services.lighting.base_url')),
            'pollSeconds' => 5,
        ];
    }

    private function target(mixed $target): ?int
    {
        if ($target === 'all' || $target === null) {
            return null;
        }

        $id = filter_var($target, FILTER_VALIDATE_INT);

        if ($id === false || ! Fixture::validId($id) || ! app(TopologyResolver::class)->resolve()->hasFixture($id)) {
            throw new InvalidArgumentException('invalid fixture');
        }

        return $id;
    }

    private function number(mixed $value): int|float|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw new InvalidArgumentException('value must be numeric');
        }

        return $value + 0;
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * @param  Closure(): LightingResult  $run
     */
    private function guarded(Closure $run): void
    {
        try {
            $result = $run();
        } catch (LightingRateLimited) {
            $this->fail(__('lighting.errors.rate_limited'));

            return;
        } catch (InvalidArgumentException) {
            $this->fail(__('lighting.errors.invalid'));

            return;
        }

        if (! $result->ok) {
            $this->fail($result->reachable ? ($result->message !== '' && str_starts_with($result->message, 'HTTP') ? __('lighting.errors.rejected') : $result->message) : __('lighting.errors.unreachable'));
        }
    }

    private function fail(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }
}
