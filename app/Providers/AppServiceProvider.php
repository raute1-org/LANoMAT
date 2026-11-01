<?php

namespace App\Providers;

use App\Models\User;
use App\Modules\Catering\Models\FoodOrder;
use App\Modules\Catering\Models\FoodOrderItem;
use App\Modules\Catering\Policies\FoodOrderItemPolicy;
use App\Modules\Catering\Policies\FoodOrderPolicy;
use App\Modules\Catering\Support\NativeOrderCards;
use App\Modules\CustomServers\Models\CustomServer;
use App\Modules\CustomServers\Policies\CustomServerPolicy;
use App\Modules\Discord\Contracts\DiscordClient;
use App\Modules\Discord\Events\DiscordGuildMemberJoined;
use App\Modules\Discord\Events\DiscordGuildMemberLeft;
use App\Modules\Discord\Events\DiscordMessageCreated;
use App\Modules\Discord\Events\DiscordMessageReactionChanged;
use App\Modules\Discord\HttpDiscordClient;
use App\Modules\Discord\Listeners\AnnounceAndCleanupOnCompleted;
use App\Modules\Discord\Listeners\AnnounceRegistrationOpen;
use App\Modules\Discord\Listeners\CreateMatchChannelOnReady;
use App\Modules\Discord\Listeners\LogGatewayEvent;
use App\Modules\Discord\Support\DiscordVoiceSightings;
use App\Modules\Events\Events\EventStatusChanged;
use App\Modules\Events\Models\Event as EventModel;
use App\Modules\Events\Policies\EventPolicy;
use App\Modules\Files\Models\SharedFile;
use App\Modules\Files\Policies\SharedFilePolicy;
use App\Modules\Files\Support\FileStores;
use App\Modules\Friends\Models\Friendship;
use App\Modules\Friends\Policies\FriendshipPolicy;
use App\Modules\Gallery\Models\EventPhoto;
use App\Modules\Gallery\Policies\EventPhotoPolicy;
use App\Modules\Games\Contracts\EggCatalog;
use App\Modules\Games\Models\Game;
use App\Modules\Games\Policies\GamePolicy;
use App\Modules\Games\Support\NullEggCatalog;
use App\Modules\GameServers\Contracts\MinecraftQuery;
use App\Modules\GameServers\Contracts\PelicanClient;
use App\Modules\GameServers\Contracts\ServerQuery;
use App\Modules\GameServers\Events\MatchScoreUpdated;
use App\Modules\GameServers\Events\ServerLinkUpdated;
use App\Modules\GameServers\HttpPelicanClient;
use App\Modules\GameServers\Listeners\CleanupServersOnCompleted;
use App\Modules\GameServers\Listeners\EnterWarmupOnServerReady;
use App\Modules\GameServers\Listeners\ProvisionMatchServerOnReady;
use App\Modules\GameServers\Listeners\UpdateMatchSurfacesOnServerReady;
use App\Modules\GameServers\Models\ServerLink;
use App\Modules\GameServers\Policies\ServerLinkPolicy;
use App\Modules\GameServers\Support\PelicanEggCatalog;
use App\Modules\GameServers\Support\TcpMinecraftQuery;
use App\Modules\GameServers\Support\UdpServerQuery;
use App\Modules\Hosts\Contracts\RemoteExecutor;
use App\Modules\Hosts\Models\RemoteHost;
use App\Modules\Hosts\Policies\RemoteHostPolicy;
use App\Modules\Hosts\SshRemoteExecutor;
use App\Modules\Identity\Connectors\HttpAvatarFetcher;
use App\Modules\Identity\Connectors\HttpGuildMembershipChecker;
use App\Modules\Identity\Connectors\HttpSteamPresence;
use App\Modules\Identity\Connectors\SteamConnector;
use App\Modules\Identity\Connectors\TwitchConnector;
use App\Modules\Identity\Contracts\AvatarFetcher;
use App\Modules\Identity\Contracts\GuildMembershipChecker;
use App\Modules\Identity\Contracts\SteamPresence;
use App\Modules\Identity\Enums\LinkedAccountProvider;
use App\Modules\Identity\Models\LinkedAccount;
use App\Modules\Identity\Policies\AvatarPolicy;
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
use App\Modules\Notifications\Listeners\NotifyUsersRegistrationOpen;
use App\Modules\Preflight\Actions\RunPreflight;
use App\Modules\Preflight\Checks\BackupFreshnessCheck;
use App\Modules\Preflight\Checks\ClientBuildCheck;
use App\Modules\Preflight\Checks\DatabaseCheck;
use App\Modules\Preflight\Checks\DependencyUpdatesCheck;
use App\Modules\Preflight\Checks\DiscordApiCheck;
use App\Modules\Preflight\Checks\DiscordGatewaySidecarCheck;
use App\Modules\Preflight\Checks\FailedJobsCheck;
use App\Modules\Preflight\Checks\FileHostCheck;
use App\Modules\Preflight\Checks\LiveKitCheck;
use App\Modules\Preflight\Checks\MumbleSidecarCheck;
use App\Modules\Preflight\Checks\MusicAssistantCheck;
use App\Modules\Preflight\Checks\PelicanCheck;
use App\Modules\Preflight\Checks\QueueWorkerCheck;
use App\Modules\Preflight\Checks\RedisCheck;
use App\Modules\Preflight\Checks\ReverbCheck;
use App\Modules\Preflight\Checks\SchedulerHeartbeatCheck;
use App\Modules\Preflight\Checks\StorageWritableCheck;
use App\Modules\Preflight\Checks\TeamSpeakSidecarCheck;
use App\Modules\Preflight\Contracts\LatestRelease;
use App\Modules\Preflight\Support\HttpLatestRelease;
use App\Modules\Presence\Contracts\GameServerDirectory;
use App\Modules\Presence\Listeners\BroadcastPresenceOnTournamentActivity;
use App\Modules\Presence\Support\NullGameServerDirectory;
use App\Modules\Presence\Support\VoiceSources;
use App\Modules\Provisioning\Contracts\HostKeyScanner;
use App\Modules\Provisioning\Contracts\HostKeyTrust;
use App\Modules\Provisioning\Contracts\HypervisorClientFactory;
use App\Modules\Provisioning\HypervisorClients;
use App\Modules\Provisioning\Models\Hypervisor;
use App\Modules\Provisioning\Models\ProvisioningRun;
use App\Modules\Provisioning\Policies\HypervisorPolicy;
use App\Modules\Provisioning\Policies\ProvisioningRunPolicy;
use App\Modules\Provisioning\Support\CrossCheckHostKeyTrust;
use App\Modules\Provisioning\Support\PhpseclibHostKeyScanner;
use App\Modules\Registration\Events\RegistrationCancelled;
use App\Modules\Registration\Models\EventRegistration;
use App\Modules\Registration\Policies\RegistrationPolicy;
use App\Modules\RoomAudio\Contracts\ReceiverClient;
use App\Modules\RoomAudio\HttpReceiverClient;
use App\Modules\RoomAudio\Policies\RoomAudioPolicy;
use App\Modules\Schedule\Contracts\ScheduleParticipantResolver;
use App\Modules\Schedule\Events\ScheduleItemModified;
use App\Modules\Schedule\Listeners\AlarmScheduleItemChanged;
use App\Modules\Schedule\Listeners\SyncScheduleOnTournamentSaved;
use App\Modules\Schedule\Models\ScheduleItem;
use App\Modules\Schedule\Policies\ScheduleItemPolicy;
use App\Modules\Schedule\Support\NativeScheduleCards;
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
use App\Modules\Voice\Domain\VoiceProvider;
use App\Modules\Voice\HttpMumbleClient;
use App\Modules\Voice\Listeners\CleanupServerVoiceOnStopped;
use App\Modules\Voice\Listeners\CleanupVoiceOnCompleted;
use App\Modules\Voice\Listeners\ProvisionMatchVoiceOnReady;
use App\Modules\Voice\Listeners\ProvisionServerVoiceOnReady;
use App\Modules\Voice\Listeners\ProvisionVoiceOnStart;
use App\Modules\Voice\Models\VoiceClientInstaller;
use App\Modules\Voice\Policies\VoiceClientInstallerPolicy;
use App\Modules\Voice\Support\VoiceServerSightings;
use App\Modules\Voice\VoiceProviders;
use App\Modules\Voting\Models\Poll;
use App\Modules\Voting\Policies\PollPolicy;
use App\Modules\Voting\Support\NativeVoteCards;
use App\Modules\WebVoice\Contracts\OrderCardSource;
use App\Modules\WebVoice\Contracts\ScheduleCardSource;
use App\Modules\WebVoice\Contracts\VoteCardSource;
use App\Modules\WebVoice\Contracts\WebVoiceClient;
use App\Modules\WebVoice\Listeners\CloseWebVoiceChannelsOnMatchCompleted;
use App\Modules\WebVoice\Listeners\CloseWebVoiceChannelsOnTournamentCompleted;
use App\Modules\WebVoice\Listeners\ProvisionWebVoiceChannelsOnMatchReady;
use App\Modules\WebVoice\Listeners\ProvisionWebVoiceTeamChannelsOnTournamentStarted;
use App\Modules\WebVoice\Models\WebVoiceChannel;
use App\Modules\WebVoice\NativeClient\Models\VoiceClientToken;
use App\Modules\WebVoice\NativeClient\Policies\VoiceClientTokenPolicy;
use App\Modules\WebVoice\NativeClient\Support\NativeGameServerDirectory;
use App\Modules\WebVoice\NativeClient\Support\Now\NullOrderCardSource;
use App\Modules\WebVoice\NativeClient\Support\Now\NullScheduleCardSource;
use App\Modules\WebVoice\NativeClient\Support\Now\NullVoteCardSource;
use App\Modules\WebVoice\Policies\WebVoiceChannelPolicy;
use App\Modules\WebVoice\Support\LiveKitClient;
use App\Modules\WebVoice\Support\WebVoiceSightings;
use App\Support\DisplayTimezone;
use App\Support\Modules\Module;
use App\Support\Modules\Modules;
use Carbon\CarbonImmutable;
use Filament\Forms\Components\DateTimePicker;
use Filament\Schemas\Schema;
use Filament\Support\Facades\FilamentTimezone;
use Filament\Tables\Table;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
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

        $this->app->bind(GuildMembershipChecker::class, HttpGuildMembershipChecker::class);
        $this->app->bind(AvatarFetcher::class, HttpAvatarFetcher::class);

        $this->app->bind(VoiceClient::class, fn () => new HttpMumbleClient(
            (string) config('services.mumble.rest_url'),
            (string) config('services.mumble.ice_secret'),
        ));

        $this->app->singleton(VoiceProviders::class);

        // "Where is everybody": Steam's view of what people play, the game server directory and the
        // voice backends that feed the location snapshot. Which of them exist is decided at resolve
        // time from the module toggles, so Presence never names Discord, Voice, WebVoice or GameServers.
        $this->app->bind(SteamPresence::class, HttpSteamPresence::class);
        $this->app->bind(GameServerDirectory::class, fn (Application $app): GameServerDirectory => $app->make(Modules::class)->enabled(Module::GameServers)
            && $app->make(Modules::class)->enabled(Module::WebVoice)
            ? $app->make(NativeGameServerDirectory::class) : new NullGameServerDirectory);
        $this->app->bind(VoiceSources::class, function (Application $app): VoiceSources {
            $modules = $app->make(Modules::class);
            $sources = [];

            if ($modules->enabled(Module::Discord)) {
                $sources[] = $app->make(DiscordVoiceSightings::class);
            }

            if ($modules->enabled(Module::Voice)) {
                foreach (VoiceProvider::cases() as $provider) {
                    $sources[] = new VoiceServerSightings($provider, $app->make(VoiceProviders::class));
                }
            }

            if ($modules->enabled(Module::WebVoice)) {
                $sources[] = $app->make(WebVoiceSightings::class);
            }

            return new VoiceSources($sources);
        });

        $this->app->bind(WebVoiceClient::class, fn () => new LiveKitClient(
            (string) config('webvoice.api_url'),
            (string) config('webvoice.api_key'),
            (string) config('webvoice.api_secret'),
            (int) config('webvoice.token_ttl'),
            (int) config('webvoice.timeout'),
        ));

        // Native client "now" feed: each optional module contributes through one contract; while the
        // module is off a null source is bound. Decided at resolve time so tests can swap Modules.
        $this->app->bind(VoteCardSource::class, fn (Application $app): VoteCardSource => $app->make(Modules::class)->enabled(Module::Voting)
            ? $app->make(NativeVoteCards::class) : new NullVoteCardSource);
        $this->app->bind(OrderCardSource::class, fn (Application $app): OrderCardSource => $app->make(Modules::class)->enabled(Module::Catering)
            ? $app->make(NativeOrderCards::class) : new NullOrderCardSource);
        $this->app->bind(ScheduleCardSource::class, fn (Application $app): ScheduleCardSource => $app->make(Modules::class)->enabled(Module::Schedule)
            ? $app->make(NativeScheduleCards::class) : new NullScheduleCardSource);

        $this->app->bind(PelicanClient::class, fn () => new HttpPelicanClient(
            (string) (config('services.pelican.url') ?? config('services.pelican.panel_url')),
            (string) config('services.pelican.application_token'),
            (string) config('services.pelican.client_token'),
            config('services.pelican.default_node_id') !== null ? (string) config('services.pelican.default_node_id') : null,
            (int) config('services.pelican.timeout'),
            (int) config('services.pelican.connect_timeout'),
        ));

        // Games must not import GameServers, so the preset form asks the Games-owned
        // EggCatalog contract; the panel-backed one is bound only while GameServers is on.
        $this->app->scoped(EggCatalog::class, fn ($app): EggCatalog => $app->make(Modules::class)->enabled(Module::GameServers)
            ? $app->make(PelicanEggCatalog::class)
            : new NullEggCatalog);

        $this->app->bind(ServerQuery::class, UdpServerQuery::class);
        $this->app->bind(MinecraftQuery::class, TcpMinecraftQuery::class);

        $this->app->bind(RemoteExecutor::class, fn () => new SshRemoteExecutor(
            (int) config('services.hosts.connect_timeout'),
            (bool) config('services.hosts.strict_host_key'),
        ));

        $this->app->singleton(FileStores::class);

        $this->app->bind(HypervisorClientFactory::class, HypervisorClients::class);
        $this->app->bind(HostKeyScanner::class, PhpseclibHostKeyScanner::class);
        $this->app->bind(HostKeyTrust::class, CrossCheckHostKeyTrust::class);

        $this->app->bind(ReceiverClient::class, fn () => new HttpReceiverClient(
            (string) config('services.room_audio.rest_url'),
            (string) config('services.room_audio.token'),
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
        $modules = app(Modules::class);

        $this->app->tag([
            DatabaseCheck::class,
            RedisCheck::class,
            StorageWritableCheck::class,
            FailedJobsCheck::class,
            ReverbCheck::class,
            SchedulerHeartbeatCheck::class,
            QueueWorkerCheck::class,
            BackupFreshnessCheck::class,
            DependencyUpdatesCheck::class,
            ...($modules->enabled(Module::Discord) ? [DiscordApiCheck::class, DiscordGatewaySidecarCheck::class] : []),
            ...($modules->enabled(Module::Voice) ? [MumbleSidecarCheck::class, TeamSpeakSidecarCheck::class] : []),
            ...($modules->enabled(Module::Files) ? [FileHostCheck::class] : []),
            ...($modules->enabled(Module::WebVoice) ? [LiveKitCheck::class, ClientBuildCheck::class] : []),
            ...($modules->enabled(Module::GameServers) ? [PelicanCheck::class] : []),
            ...($modules->enabled(Module::Jukebox) ? [MusicAssistantCheck::class] : []),
        ], 'preflight.checks');

        $this->app->bind(LatestRelease::class, HttpLatestRelease::class);

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
        $this->configureDisplayTimezone();
        $this->configureUrls();
        $this->configureRouteParameterPatterns();
        $this->configureSocialite();
        $this->configureAuthorization();
        $this->configureEventListeners();
    }

    /**
     * Storage stays UTC; Filament date-time pickers/columns read and write in
     * the single display timezone (see config/lanomat.php).
     */
    private function configureDisplayTimezone(): void
    {
        FilamentTimezone::set(fn (): string => DisplayTimezone::name());

        // German date order everywhere in the panel (31.10.2026 17:40) instead
        // of Filament's US defaults ("Oct 31, 2026 17:40:18"); pickers use
        // Filament's own (non-native) picker so the format is not left to the
        // browser locale.
        Table::configureUsing(fn (Table $table): Table => $table
            ->defaultDateDisplayFormat('d.m.Y')
            ->defaultDateTimeDisplayFormat('d.m.Y H:i')
            ->defaultTimeDisplayFormat('H:i'));
        Schema::configureUsing(fn (Schema $schema): Schema => $schema
            ->defaultDateDisplayFormat('d.m.Y')
            ->defaultDateTimeDisplayFormat('d.m.Y H:i')
            ->defaultTimeDisplayFormat('H:i'));
        DateTimePicker::configureUsing(fn (DateTimePicker $picker): DateTimePicker => $picker
            ->native(false)
            ->seconds(false)
            ->firstDayOfWeek(1)
            ->defaultDateDisplayFormat('d.m.Y')
            ->defaultDateTimeDisplayFormat('d.m.Y H:i')
            ->defaultDateTimeWithSecondsDisplayFormat('d.m.Y H:i:s')
            ->defaultTimeDisplayFormat('H:i'));
    }

    /**
     * Every id-style route parameter is a bigint primary key: anything else (or a
     * value beyond bigint) must 404 at routing time instead of reaching the
     * database, where Postgres would reject it and surface as a 500. Slug and
     * token parameters (`{event:slug}`, `{token}`, `{provider}`) are unaffected.
     */
    protected function configureRouteParameterPatterns(): void
    {
        foreach ([
            'user', 'team', 'teamRequest', 'tournament', 'match', 'seat', 'eventPhoto',
            'foodOrder', 'foodOrderItem', 'friendship', 'installer', 'item', 'jukeboxItem', 'lfgPost',
            'poll', 'scene', 'serverLink', 'sharedFile', 'tombolaPrize',
        ] as $parameter) {
            Route::pattern($parameter, '[0-9]{1,18}');
        }

        // Database notifications are keyed by UUID, not bigint.
        Route::pattern('notification', '[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}');
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
     * Generate https URLs in production. Trusted proxies (bootstrap/app.php)
     * already honour X-Forwarded-Proto from Traefik; forcing the scheme here
     * guarantees OAuth redirects and asset URLs stay https even if a request
     * ever arrives without that header.
     */
    protected function configureUrls(): void
    {
        URL::forceHttps(app()->isProduction());
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
        RateLimiter::for('csp-report', fn (Request $request) => Limit::perMinute(60)->by('csp-report|'.$request->ip()));
        RateLimiter::for('file-upload-tickets', fn (Request $request) => Limit::perMinute(max(1, (int) config('files.tus.tickets_per_minute')))->by('file-upload-tickets|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('self-checkin', fn (Request $request) => Limit::perMinute(30)->by('self-checkin|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('web-voice-chat', fn (Request $request) => Limit::perSecond((int) config('webvoice.chat.rate_limit'), (int) config('webvoice.chat.rate_window'))->by('web-voice-chat|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));

        // Native voice client: the device endpoints are unauthenticated, so they are limited per IP
        // (and per device code) and the approval form per user (it guesses a short user code).
        RateLimiter::for('voice-client-device', fn (Request $request) => Limit::perMinute(5)->by('voice-client-device|'.$request->ip()));
        RateLimiter::for('voice-client-token', fn (Request $request) => [
            Limit::perMinute(30)->by('voice-client-token|'.$request->ip()),
            Limit::perMinute(30)->by('voice-client-token-code|'.hash('sha256', is_string($request->input('device_code')) ? $request->input('device_code') : '')),
        ]);
        RateLimiter::for('voice-client-approve', fn (Request $request) => Limit::perMinute(10)->by('voice-client-approve|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('voice-client-api', fn (Request $request) => Limit::perMinute(120)->by('voice-client-api|'.hash('sha256', (string) $request->bearerToken()).'|'.$request->ip()));

        // Avatars: a page loads dozens at once, and every one is immutable and cacheable afterwards.
        RateLimiter::for('avatars', fn (Request $request) => Limit::perMinute(600)->by('avatars|'.($request->user()?->getAuthIdentifier() ?? $request->ip())));
        RateLimiter::for('voice-client-avatars', fn (Request $request) => Limit::perMinute(600)->by('voice-client-avatars|'.hash('sha256', (string) $request->bearerToken()).'|'.$request->ip()));

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
        Gate::policy(LinkedAccount::class, LinkedAccountPolicy::class);
        // A named ability, not a User policy: a policy on User would also switch on Filament's UserResource authorization.
        Gate::define('view-avatar', [AvatarPolicy::class, 'view']);

        if ($this->on(Module::Schedule)) {
            Gate::policy(ScheduleItem::class, ScheduleItemPolicy::class);
        }

        if ($this->on(Module::Catering)) {
            Gate::policy(FoodOrder::class, FoodOrderPolicy::class);
            Gate::policy(FoodOrderItem::class, FoodOrderItemPolicy::class);
        }

        if ($this->on(Module::Voting)) {
            Gate::policy(Poll::class, PollPolicy::class);
        }

        if ($this->on(Module::Lfg)) {
            Gate::policy(LfgPost::class, LfgPostPolicy::class);
        }

        if ($this->on(Module::Files)) {
            Gate::policy(SharedFile::class, SharedFilePolicy::class);
        }

        if ($this->on(Module::Gallery)) {
            Gate::policy(EventPhoto::class, EventPhotoPolicy::class);
        }

        if ($this->on(Module::Infoscreen)) {
            Gate::policy(InfoscreenScene::class, InfoscreenScenePolicy::class);
            Gate::policy(TombolaPrize::class, TombolaPrizePolicy::class);
        }

        if ($this->on(Module::GameServers)) {
            Gate::policy(ServerLink::class, ServerLinkPolicy::class);
        }

        if ($this->on(Module::Hosts)) {
            Gate::policy(RemoteHost::class, RemoteHostPolicy::class);
        }

        if ($this->on(Module::Provisioning)) {
            Gate::policy(Hypervisor::class, HypervisorPolicy::class);
            Gate::policy(ProvisioningRun::class, ProvisioningRunPolicy::class);
        }

        if ($this->on(Module::CustomServers)) {
            Gate::policy(CustomServer::class, CustomServerPolicy::class);
        }

        if ($this->on(Module::Voice)) {
            Gate::policy(VoiceClientInstaller::class, VoiceClientInstallerPolicy::class);
        }

        if ($this->on(Module::WebVoice)) {
            Gate::policy(WebVoiceChannel::class, WebVoiceChannelPolicy::class);
            Gate::policy(VoiceClientToken::class, VoiceClientTokenPolicy::class);
        }

        if ($this->on(Module::Friends)) {
            Gate::policy(Friendship::class, FriendshipPolicy::class);
        }

        if ($this->on(Module::News)) {
            Gate::policy(NewsPost::class, NewsPostPolicy::class);
        }

        Gate::define('claim-seat', [SeatAssignmentPolicy::class, 'claim']);

        if ($this->on(Module::Jukebox)) {
            Gate::define('jukebox.participate', [JukeboxPolicy::class, 'participate']);
            Gate::define('jukebox.moderate', [JukeboxPolicy::class, 'moderate']);
        }

        if ($this->on(Module::RoomAudio)) {
            Gate::define('room-audio.control', [RoomAudioPolicy::class, 'control']);
        }
    }

    private function on(Module $module): bool
    {
        return app(Modules::class)->enabled($module);
    }

    /**
     * Register cross-module event listeners (sanctioned inter-module
     * communication per the M2 seating/registration plan).
     */
    protected function configureEventListeners(): void
    {
        // Registration order per event is preserved from the pre-gating
        // listener list, so the full profile dispatches exactly as before.
        Event::listen(RegistrationCancelled::class, ReleaseSeatOnCancellation::class);
        // The bell works without Discord; the Discord listener only mirrors the channel announcement.
        Event::listen(EventStatusChanged::class, NotifyUsersRegistrationOpen::class);

        if ($this->on(Module::Discord)) {
            Event::listen(EventStatusChanged::class, AnnounceRegistrationOpen::class);
            Event::listen(MatchReady::class, CreateMatchChannelOnReady::class);
            Event::listen(MatchCompleted::class, AnnounceAndCleanupOnCompleted::class);
            Event::listen(DiscordGuildMemberJoined::class, LogGatewayEvent::class);
            Event::listen(DiscordGuildMemberLeft::class, LogGatewayEvent::class);
            Event::listen(DiscordMessageCreated::class, LogGatewayEvent::class);
            Event::listen(DiscordMessageReactionChanged::class, LogGatewayEvent::class);
        }

        Event::listen(MatchReady::class, NotifyRosterOnMatchReady::class);

        if ($this->on(Module::Infoscreen)) {
            Event::listen(MatchCompleted::class, BroadcastWinnerMoment::class);
            Event::listen(MatchWentLive::class, GongOnMatchLive::class);
            Event::listen(MatchScoreUpdated::class, BroadcastScoreboardOnScoreUpdated::class);
        }

        if ($this->on(Module::Voice)) {
            Event::listen(TournamentStarted::class, ProvisionVoiceOnStart::class);
            Event::listen(MatchReady::class, ProvisionMatchVoiceOnReady::class);
            Event::listen(TournamentCompleted::class, CleanupVoiceOnCompleted::class);
        }

        if ($this->on(Module::WebVoice)) {
            Event::listen(MatchReady::class, ProvisionWebVoiceChannelsOnMatchReady::class);
            Event::listen(TournamentStarted::class, ProvisionWebVoiceTeamChannelsOnTournamentStarted::class);
            Event::listen(MatchCompleted::class, CloseWebVoiceChannelsOnMatchCompleted::class);
            Event::listen(TournamentCompleted::class, CloseWebVoiceChannelsOnTournamentCompleted::class);
        }

        if ($this->on(Module::GameServers)) {
            Event::listen(MatchReady::class, ProvisionMatchServerOnReady::class);
            Event::listen(TournamentCompleted::class, CleanupServersOnCompleted::class);
            // Injects DiscordClient directly (bypasses the DiscordChannel guard), so it also needs Discord.
            if ($this->on(Module::Discord)) {
                Event::listen(ServerLinkUpdated::class, UpdateMatchSurfacesOnServerReady::class);
            }
            Event::listen(ServerLinkUpdated::class, EnterWarmupOnServerReady::class);
        }

        if ($this->on(Module::Voice)) {
            Event::listen(ServerLinkUpdated::class, ProvisionServerVoiceOnReady::class);
            Event::listen(ServerLinkUpdated::class, CleanupServerVoiceOnStopped::class);
        }

        if ($this->on(Module::Schedule)) {
            Event::listen(TournamentSaved::class, SyncScheduleOnTournamentSaved::class);
            Event::listen(ScheduleItemModified::class, AlarmScheduleItemChanged::class);
        }

        // AnnounceLfgPost injects DiscordClient directly, so it also needs Discord.
        if ($this->on(Module::Lfg) && $this->on(Module::Discord)) {
            Event::listen(LfgPostCreated::class, AnnounceLfgPost::class);
        }

        if ($this->on(Module::Presence)) {
            Event::listen(MatchReady::class, BroadcastPresenceOnTournamentActivity::class);
            Event::listen(MatchWentLive::class, BroadcastPresenceOnTournamentActivity::class);
            Event::listen(MatchCompleted::class, BroadcastPresenceOnTournamentActivity::class);
            Event::listen(TournamentStarted::class, BroadcastPresenceOnTournamentActivity::class);
        }
    }
}
