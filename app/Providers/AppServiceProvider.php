<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Policies\FoodOrderItemPolicy;
use App\Modules\Catering\Policies\FoodOrderPolicy;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\CustomServers\Policies\CustomServerPolicy;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\HttpDiscordClient;
use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\Discord\Listeners\AnnounceRegistrationOpen;
use App\Modules\Discord\Listeners\CreateMatchChannelOnReady;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Events\Policies\EventPolicy;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Policies\SharedFilePolicy;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Policies\FriendshipPolicy;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Policies\EventPhotoPolicy;
use App\Modules\Games\Models\Game;
use App\Modules\Games\Policies\GamePolicy;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\HttpPelicanClient;
use App\Modules\GameServers\Listeners\CleanupServersOnCompleted;
use App\Modules\GameServers\Listeners\EnterWarmupOnServerReady;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Listeners\UpdateMatchSurfacesOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Policies\ServerLinkPolicy;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\Policies\RemoteHostPolicy;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Identity\Connectors\SteamConnector;
use App\Modules\Identity\Connectors\TwitchConnector;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Policies\LinkedAccountPolicy;
use App\Modules\Identity\Support\LinkedAccountConnectors;
use App\Modules\Infoscreen\Listeners\BroadcastScoreboardOnScoreUpdated;
use App\Modules\Infoscreen\Listeners\BroadcastWinnerMoment;
use App\Modules\Infoscreen\Listeners\GongOnMatchLive;
use App\Modules\Infoscreen\Models\InfoscreenScene;
use App\Modules\Infoscreen\Models\TombolaPrize;
use App\Modules\Infoscreen\Policies\InfoscreenScenePolicy;
use App\Modules\Infoscreen\Policies\TombolaPrizePolicy;
use App\Modules\Jukebox\Contracts\MusicClient;
use App\Modules\Jukebox\MusicAssistant\HttpMusicClient;
use App\Modules\Jukebox\Policies\JukeboxPolicy;
use App\Modules\Lfg\Events\LfgPostCreated;
use App\Modules\Lfg\Listeners\AnnounceLfgPost;
use App\Modules\Lfg\Models\LfgPost;
use App\Modules\Lfg\Policies\LfgPostPolicy;
use App\Modules\News\Models\NewsPost;
use App\Modules\News\Policies\NewsPostPolicy;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Presence\Listeners\BroadcastPresenceOnTournamentActivity;
use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Policies\RegistrationPolicy;
use App\Modules\Schedule\Contracts\ScheduleParticipantResolver;
use App\Modules\Schedule\Events\ScheduleItemTimeChanged;
use App\Modules\Schedule\Listeners\AlarmScheduleItemChanged;
use App\Modules\Schedule\Listeners\SyncScheduleOnTournamentSaved;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Policies\ScheduleItemPolicy;
use App\Modules\Seating\Listeners\ReleaseSeatOnCancellation;
use App\Modules\Seating\Models\Seat;
use App\Modules\Seating\Policies\SeatAssignmentPolicy;
use App\Modules\Seating\Policies\SeatPolicy;
use App\Modules\Teams\Models\Team;
use App\Modules\Teams\Policies\TeamPolicy;
use App\Modules\Tournaments\Events\MatchCompleted;
use App\Modules\Tournaments\Events\MatchReady;
use App\Modules\Tournaments\Events\MatchWentLive;
use App\Modules\Tournaments\Events\TournamentCompleted;
use App\Modules\Tournaments\Events\TournamentSaved;
use App\Modules\Tournaments\Events\TournamentStarted;
use App\Modules\Tournaments\Listeners\NotifyRosterOnMatchReady;
use App\Modules\Tournaments\Models\GameMatch;
use App\Modules\Tournaments\Models\MatchReport;
use App\Modules\Tournaments\Models\Tournament;
use App\Modules\Tournaments\Models\TournamentEntry;
use App\Modules\Tournaments\Policies\TournamentPolicy;
use App\Modules\Tournaments\Support\TournamentScheduleParticipantResolver;
use App\Modules\Voice\Contracts\VoiceClient;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\Listeners\CleanupVoiceOnCompleted;
use App\Modules\Voice\Listeners\ProvisionMatchVoiceOnReady;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use App\Modules\Voice\Listeners\ProvisionVoiceOnStart;
use App\Modules\Voice\Models\VoiceClientInstaller;
use App\Modules\Voice\Policies\VoiceClientInstallerPolicy;
use App\Modules\Voice\VoiceProviders;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Policies\PollPolicy;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use SocialiteProviders\Discord\Provider as DiscordProvider;
use SocialiteProviders\Manager\SocialiteWasCalled;
use SocialiteProviders\Steam\Provider as SteamProvider;
use SocialiteProviders\Twitch\Provider as TwitchProvider;

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

        $this->app->bind(VoiceClient::class, fn () => new HttpMumbleClient(
            (string) config('services.mumble.rest_url'),
            (string) config('services.mumble.ice_secret'),
        ));

        $this->app->singleton(VoiceProviders::class);

        $this->app->bind(PelicanClient::class, fn () => new HttpPelicanClient(
            (string) config('services.pelican.panel_url'),
            (string) config('services.pelican.application_token'),
            (string) config('services.pelican.client_token'),
            config('services.pelican.node_id'),
        ));

        $this->app->bind(RemoteExecutor::class, fn () => new SshRemoteExecutor(
            (int) config('services.hosts.connect_timeout'),
            (bool) config('services.hosts.strict_host_key'),
        ));

        $this->app->bind(MusicClient::class, fn () => new HttpMusicClient(
            (string) config('services.music_assistant.base_url'),
            (string) config('services.music_assistant.token'),
            (string) config('services.music_assistant.player_id'),
        ));

        $this->app->bind(ScheduleParticipantResolver::class, TournamentScheduleParticipantResolver::class);

        $this->app->singleton(LinkedAccountConnectors::class);

        // Real per-provider connectors are bound under
        // LinkedAccountConnectors::abstractFor($provider). Steam (9.3) and
        // Twitch (9.4) are wired here. Until a provider is bound,
        // LinkedAccountConnectors::for() throws
        // IdentityException::unknownLinkedAccountProvider(). Tests bind
        // fakes via the fakeLinkedAccounts() helper (tests/Pest.php), which
        // takes precedence over these bindings.
        $this->app->bind(
            LinkedAccountConnectors::abstractFor(LinkedAccountProvider::Steam),
            SteamConnector::class,
        );

        $this->app->bind(
            LinkedAccountConnectors::abstractFor(LinkedAccountProvider::Twitch),
            TwitchConnector::class,
        );

        // Preflight health checks are tagged here; each Checks/* class is added to
        // this array by the task that creates it (see the preflight plan Tasks 2-4).
        $this->app->tag([], 'preflight.checks');

        $this->app->bind(
            RunPreflight::class,
            fn ($app) => new RunPreflight($app->tagged('preflight.checks')),
        );
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
            $event->extendSocialite('steam', SteamProvider::class);
            $event->extendSocialite('twitch', TwitchProvider::class);
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
        Gate::policy(Game::class, GamePolicy::class);
        Gate::policy(Tournament::class, TournamentPolicy::class);
        Gate::policy(TournamentEntry::class, TournamentPolicy::class);
        Gate::policy(GameMatch::class, TournamentPolicy::class);
        Gate::policy(MatchReport::class, TournamentPolicy::class);
        Gate::policy(ScheduleItem::class, ScheduleItemPolicy::class);
        Gate::policy(FoodOrder::class, FoodOrderPolicy::class);
        Gate::policy(FoodOrderItem::class, FoodOrderItemPolicy::class);
        Gate::policy(Poll::class, PollPolicy::class);
        Gate::policy(LfgPost::class, LfgPostPolicy::class);
        Gate::policy(SharedFile::class, SharedFilePolicy::class);
        Gate::policy(EventPhoto::class, EventPhotoPolicy::class);
        Gate::policy(InfoscreenScene::class, InfoscreenScenePolicy::class);
        Gate::policy(TombolaPrize::class, TombolaPrizePolicy::class);
        Gate::policy(ServerLink::class, ServerLinkPolicy::class);
        Gate::policy(RemoteHost::class, RemoteHostPolicy::class);
        Gate::policy(CustomServer::class, CustomServerPolicy::class);
        Gate::policy(VoiceClientInstaller::class, VoiceClientInstallerPolicy::class);
        Gate::policy(LinkedAccount::class, LinkedAccountPolicy::class);
        Gate::policy(Friendship::class, FriendshipPolicy::class);
        Gate::policy(NewsPost::class, NewsPostPolicy::class);

        Gate::define('claim-seat', [SeatAssignmentPolicy::class, 'claim']);
        Gate::define('jukebox.participate', [JukeboxPolicy::class, 'participate']);
        Gate::define('jukebox.moderate', [JukeboxPolicy::class, 'moderate']);
    }

    /**
     * Register cross-module event listeners (sanctioned inter-module
     * communication per the M2 seating/registration plan).
     */
    protected function configureEventListeners(): void
    {
        Event::listen(RegistrationCancelled::class, ReleaseSeatOnCancellation::class);
        Event::listen(EventStatusChanged::class, AnnounceRegistrationOpen::class);
        Event::listen(MatchReady::class, CreateMatchChannelOnReady::class);
        Event::listen(MatchReady::class, NotifyRosterOnMatchReady::class);
        Event::listen(MatchCompleted::class, AnnounceAndCleanupOnCompleted::class);
        Event::listen(MatchCompleted::class, BroadcastWinnerMoment::class);
        Event::listen(MatchWentLive::class, GongOnMatchLive::class);
        Event::listen(MatchScoreUpdated::class, BroadcastScoreboardOnScoreUpdated::class);
        Event::listen(TournamentStarted::class, ProvisionVoiceOnStart::class);
        Event::listen(MatchReady::class, ProvisionMatchVoiceOnReady::class);
        Event::listen(TournamentCompleted::class, CleanupVoiceOnCompleted::class);
        Event::listen(MatchReady::class, ProvisionMatchServerOnReady::class);
        Event::listen(TournamentCompleted::class, CleanupServersOnCompleted::class);
        Event::listen(ServerLinkUpdated::class, UpdateMatchSurfacesOnServerReady::class);
        Event::listen(ServerLinkUpdated::class, EnterWarmupOnServerReady::class);
        Event::listen(ServerLinkUpdated::class, ProvisionServerVoiceOnReady::class);
        Event::listen(TournamentSaved::class, SyncScheduleOnTournamentSaved::class);
        Event::listen(LfgPostCreated::class, AnnounceLfgPost::class);
        Event::listen(ScheduleItemTimeChanged::class, AlarmScheduleItemChanged::class);
        Event::listen(MatchReady::class, BroadcastPresenceOnTournamentActivity::class);
        Event::listen(MatchWentLive::class, BroadcastPresenceOnTournamentActivity::class);
        Event::listen(MatchCompleted::class, BroadcastPresenceOnTournamentActivity::class);
        Event::listen(TournamentStarted::class, BroadcastPresenceOnTournamentActivity::class);
    }
}
