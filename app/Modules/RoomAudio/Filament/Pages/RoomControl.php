<?php

declare(strict_types=1);

namespace App\Modules\RoomAudio\Filament\Pages;

use App\Models\User;
use App\Modules\RoomAudio\Actions\SetReceiverMode;
use App\Modules\RoomAudio\Actions\SetReceiverMute;
use App\Modules\RoomAudio\Actions\SetReceiverPower;
use App\Modules\RoomAudio\Actions\SetReceiverSource;
use App\Modules\RoomAudio\Actions\SetReceiverVolume;
use App\Modules\RoomAudio\Actions\SetVolumeCeiling;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\Exceptions\ReceiverUnavailable;
use App\Modules\RoomAudio\Models\RoomAudioSetting;
use App\Modules\RoomAudio\Support\ReceiverState;
use App\Providers\Filament\AdminNavigationGroup;
use BackedEnum;
use Closure;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;
use InvalidArgumentException;
use UnitEnum;

/**
 * Orga control of the room receiver. Every value shown comes from the
 * ReceiverClient on each render; browser-supplied values only ever travel into
 * the Actions, which clamp and validate them.
 */
class RoomControl extends Page
{
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSpeakerWave;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Programm;

    protected static ?string $slug = 'room-control';

    protected string $view = 'filament.pages.room-control';

    public static function canAccess(): bool
    {
        return auth()->user()?->can('room-audio.control') ?? false;
    }

    public static function getNavigationLabel(): string
    {
        return __('room_audio.nav');
    }

    public function getTitle(): string|Htmlable
    {
        return __('room_audio.title');
    }

    public function setVolume(float $v): void
    {
        $this->guarded(fn () => app(SetReceiverVolume::class)($this->actor(), $v));
    }

    public function volumeStep(int $direction): void
    {
        $this->guarded(function () use ($direction): void {
            $current = app(ReceiverClient::class)->state()->volume ?? 0.0;

            app(SetReceiverVolume::class)($this->actor(), $current + ($direction <=> 0));
        });
    }

    public function toggleMute(): void
    {
        $this->guarded(function (): void {
            app(SetReceiverMute::class)($this->actor(), ! app(ReceiverClient::class)->state()->mute);
        });
    }

    public function togglePower(): void
    {
        $this->guarded(function (): void {
            $state = app(ReceiverClient::class)->state();

            // Unreachable: the sidecar cannot act on a power command either.
            if (! $state->connected) {
                throw ReceiverUnavailable::unreachable();
            }

            app(SetReceiverPower::class)($this->actor(), ! $state->power);
        });
    }

    public function setSource(string $code): void
    {
        $this->guarded(fn () => app(SetReceiverSource::class)($this->actor(), $code));
    }

    public function setMode(string $key): void
    {
        $this->guarded(fn () => app(SetReceiverMode::class)($this->actor(), $key));
    }

    public function saveCeiling(int $c): void
    {
        $this->guarded(function () use ($c): void {
            app(SetVolumeCeiling::class)($this->actor(), $c);

            Notification::make()->success()->title(__('room_audio.ceiling_saved'))->send();
        });
    }

    /**
     * @return array{state: ReceiverState, ceiling: int}
     */
    protected function getViewData(): array
    {
        return [
            'state' => app(ReceiverClient::class)->state(),
            'ceiling' => RoomAudioSetting::current()->volume_ceiling,
        ];
    }

    private function actor(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }

    private function guarded(Closure $run): void
    {
        try {
            $run();
        } catch (ReceiverUnavailable) {
            $this->fail(__('room_audio.errors.unavailable'));
        } catch (InvalidArgumentException) {
            $this->fail(__('room_audio.errors.invalid'));
        }
    }

    private function fail(string $message): void
    {
        Notification::make()->danger()->title($message)->send();
    }
}
