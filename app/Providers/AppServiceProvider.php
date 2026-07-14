<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\HttpDiscordClient;
use App\Modules\Discord\Listeners\AnnounceRegistrationOpen;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Events\Policies\EventPolicy;
use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Policies\RegistrationPolicy;
use App\Modules\Seating\Listeners\ReleaseSeatOnCancellation;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Policies\SeatAssignmentPolicy;
use App\Modules\Seating\Policies\SeatPolicy;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Policies\TeamPolicy;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(DiscordClient::class, fn () => new HttpDiscordClient(
            (string) config('services.discord.bot_token'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureSocialite();
        $this->configureAuthorization();
        $this->configureEventListeners();
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register third-party Socialite providers (SocialiteProviders.com).
     */
    protected function configureSocialite(): void
    {
        Event::listen(function (SocialiteWasCalled $event) {
            $event->extendSocialite('discord', DiscordProvider::class);
        });
    }

    /**
     * Configure authorization and gates.
     */
    protected function configureAuthorization(): void
    {
        Gate::before(fn (User $user) => $user->isAdmin() ? true : null);

        Gate::policy(EventModel::class, EventPolicy::class);
        Gate::policy(EventRegistration::class, RegistrationPolicy::class);
        Gate::policy(Seat::class, SeatPolicy::class);
        Gate::policy(Team::class, TeamPolicy::class);
        Gate::policy(Tournament::class, TournamentPolicy::class);
        Gate::policy(TournamentEntry::class, TournamentPolicy::class);
        Gate::policy(GameMatch::class, TournamentPolicy::class);
        Gate::policy(MatchReport::class, TournamentPolicy::class);

        Gate::define('claim-seat', [SeatAssignmentPolicy::class, 'claim']);
    }

    /**
     * Register cross-module event listeners (sanctioned inter-module
     * communication per the M2 seating/registration plan).
     */
    protected function configureEventListeners(): void
    {
        Event::listen(RegistrationCancelled::class, ReleaseSeatOnCancellation::class);
        Event::listen(EventStatusChanged::class, AnnounceRegistrationOpen::class);
    }
}
