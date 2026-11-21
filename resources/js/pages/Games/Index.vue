<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    Download,
    Filter,
    MonitorPlay,
    Search,
    Server,
    Share2,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed, ref } from 'vue';
import GamesSidebar from '@/components/games/GamesSidebar.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { formatInstant } from '@/lib/datetime';

/** The compact live readout of the last server that ran this game. */
type LastPlayedServer = {
    title: string;
    map: string;
    players: number;
    max_players: number;
    checked_at: string | null;
};

type GameCard = {
    slug: string;
    title: string;
    summary: string | null;
    platforms: string[] | null;
    version: string | null;
    lan_ready: boolean;
    installable: boolean;
    install_deeplink: string;
    avatar_converter_url: string | null;
    server_count: number;
    running_tournaments: {
        id: number;
        name: string;
        status: string;
        url: string;
    }[];
    running_tournaments_count: number;
    open_lfg: number;
    play_url: string | null;
    /** "Ein Server · Nickname · Join/Leave" for a browser recipe. */
    server_note: string | null;
    supports_multiplayer: boolean;
    launcher_available: boolean;
    launcher_url: string | null;
    /** Whether the published launcher build can actually install this game. */
    launcher_supported: boolean;
    /** Last real server activity for this game, or null when it never ran. */
    last_played_at: string | null;
    last_played_server: LastPlayedServer | null;
};

/** A card that actually has server activity (the shape the recent list needs). */
type RecentGame = GameCard & { last_played_at: string };

const props = defineProps<{
    games: GameCard[];
    active_event: { slug: string; name: string } | null;
    game_modules: { game_servers: boolean; web_voice: boolean; lfg: boolean };
    filters: { only_lan: boolean };
}>();

const query = ref('');
// The toggle lives in the URL (?only_lan=1) so a reload, a shared link and the
// server-side filter all agree. A partial reload only re-fetches games+filters.
const onlyLan = computed(() => props.filters.only_lan);

// Only a full GET visit swaps the page for skeletons; the LAN filter is a
// partial reload and keeps the list on screen (see usePageLoadState).
const { isNavigating, hasError } = usePageLoadState();

function retry(): void {
    router.reload();
}

function toggleOnlyLan(): void {
    router.get('/games', onlyLan.value ? {} : { only_lan: 1 }, {
        only: ['games', 'filters'],
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
}

const filtered = computed(() => {
    const needle = query.value.trim().toLowerCase();

    return props.games.filter((game) => {
        if (onlyLan.value && !game.lan_ready) {
            return false;
        }

        if (needle === '') {
            return true;
        }

        return (
            game.title.toLowerCase().includes(needle) ||
            (game.summary ?? '').toLowerCase().includes(needle) ||
            game.slug.includes(needle)
        );
    });
});

// The server already sorts by activity; the top section is just the first few
// of those cards, so it follows the search and the LAN filter too.
const recent = computed<RecentGame[]>(() =>
    filtered.value
        .filter((game): game is RecentGame => game.last_played_at !== null)
        .slice(0, 4),
);

const lanCount = computed(
    () => props.games.filter((game) => game.lan_ready).length,
);

const platformLabel = (platforms: string[] | null): string =>
    (platforms ?? [])
        .map((p) =>
            p === 'windows' ? 'Windows' : p === 'linux' ? 'Linux' : 'macOS',
        )
        .join(' · ');

/**
 * The first-user path: with a published launcher build that can actually
 * install this game, "Installieren" opens the launcher download instead of
 * firing `lanomat://` into a PC that has no handler for it yet. The deep link
 * stays as the quiet "Im Launcher öffnen" action beside it.
 *
 * `launcher_supported` is the server's answer for this recipe (the launcher's
 * generic engine), so the same rule holds for every game it can install.
 */
function launcherPrimary(game: GameCard): boolean {
    return (
        game.installable &&
        game.launcher_supported &&
        game.launcher_available &&
        game.launcher_url !== null
    );
}

function installHref(game: GameCard): string {
    if (launcherPrimary(game) && game.launcher_url !== null) {
        return game.launcher_url;
    }

    // Without a build (or nothing to install) the detail page is the way in;
    // it holds the deep link, the command and the file list.
    return `/games/${game.slug}`;
}

const relativeFormat = new Intl.RelativeTimeFormat('de-DE', {
    numeric: 'auto',
});

/** "vor 12 Minuten" — absolute date once it is older than a week. */
function relativeTime(iso: string): string {
    const minutes = Math.round((new Date(iso).getTime() - Date.now()) / 60_000);

    if (minutes > -1) {
        return 'gerade eben';
    }

    if (minutes > -60) {
        return relativeFormat.format(minutes, 'minute');
    }

    const hours = Math.round(minutes / 60);

    if (hours > -24) {
        return relativeFormat.format(hours, 'hour');
    }

    const days = Math.round(hours / 24);

    if (days > -7) {
        return relativeFormat.format(days, 'day');
    }

    return formatInstant(iso, {
        day: '2-digit',
        month: '2-digit',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Absolute instant in the display timezone (Europe/Berlin), for the tooltip. */
function absoluteTime(iso: string): string {
    return formatInstant(iso, { dateStyle: 'medium', timeStyle: 'short' });
}
</script>

<template>
    <Head title="Spiele" />

    <PageContainer
        width="wide"
        title="Spiele"
        description="Was auf dieser LAN läuft — installieren, starten, zusammen spielen."
    >
        <template #actions>
            <Button as-child variant="outline" size="sm" data-test="share-game">
                <Link href="/games/share">
                    <Share2 class="size-4" aria-hidden="true" />
                    Spiel teilen
                </Link>
            </Button>
        </template>

        <!-- loading: a full visit only, e.g. entering the page again -->
        <div
            v-if="isNavigating"
            class="mt-6 grid gap-8 lg:grid-cols-[16rem_1fr]"
            role="status"
            aria-label="Spiele werden geladen"
            data-test="games-loading"
        >
            <div class="space-y-2">
                <Skeleton class="h-5 w-24 rounded-md" />
                <Skeleton
                    v-for="n in 6"
                    :key="n"
                    class="h-12 w-full rounded-md"
                />
            </div>
            <div class="space-y-4">
                <div class="flex flex-wrap gap-3">
                    <Skeleton class="h-9 min-w-56 flex-1 rounded-md" />
                    <Skeleton class="h-9 w-40 rounded-md" />
                </div>
                <Skeleton class="h-4 w-64 rounded-md" />
                <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
                    <Skeleton
                        v-for="n in 6"
                        :key="n"
                        class="h-64 w-full rounded-md"
                    />
                </div>
            </div>
        </div>

        <!-- error -->
        <div
            v-else-if="hasError"
            class="mt-6 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center"
            role="alert"
            data-test="games-error"
        >
            <p class="text-sm text-destructive">
                Die Spiele konnten nicht geladen werden. Prüfe deine Verbindung
                und versuch es noch einmal.
            </p>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="mt-3"
                @click="retry"
            >
                Erneut versuchen
            </Button>
        </div>

        <div v-else class="mt-6 grid gap-8 lg:grid-cols-[16rem_1fr]">
            <GamesSidebar :games="games" active="" />

            <section>
                <div class="flex flex-wrap items-center gap-3">
                    <label class="relative min-w-56 flex-1">
                        <Search
                            class="pointer-events-none absolute top-1/2 left-3 size-4 -translate-y-1/2 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <Input
                            v-model="query"
                            class="pl-9"
                            type="search"
                            placeholder="Spiel suchen — ut2004, ridge racer …"
                            aria-label="Spiel suchen"
                        />
                    </label>
                    <Button
                        type="button"
                        :variant="onlyLan ? 'default' : 'outline'"
                        :aria-pressed="onlyLan"
                        data-test="only-lan"
                        @click="toggleOnlyLan"
                    >
                        <Filter class="size-4" aria-hidden="true" />
                        Nur LAN-Spiele
                        <span class="font-mono text-xs opacity-70">{{
                            lanCount
                        }}</span>
                    </Button>
                </div>

                <p
                    v-if="active_event"
                    class="mt-3 font-mono text-xs text-muted-foreground"
                >
                    ● LIVE {{ active_event.name }}
                    <span v-if="game_modules.web_voice">
                        — Sprachchat offen</span
                    >
                </p>
                <p
                    v-else
                    class="mt-3 font-mono text-xs text-muted-foreground"
                    data-test="no-active-event"
                >
                    Kein Event aktiv — die Bibliothek bleibt offen. Server und
                    Runden erscheinen, sobald die LAN läuft.
                </p>

                <!-- What actually ran on the LAN, newest first. The server owns
                     the order and the timestamps; this section is the first few
                     of the same cards, so it follows the LAN filter and the
                     search. No server activity at all -> no section. -->
                <section
                    v-if="recent.length > 0"
                    class="mt-6"
                    aria-labelledby="recent-games-heading"
                    data-test="recent-games"
                >
                    <div
                        class="flex flex-wrap items-baseline justify-between gap-2"
                    >
                        <h2
                            id="recent-games-heading"
                            class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            Zuletzt gespielt
                        </h2>
                        <p class="font-mono text-[10px] text-muted-foreground">
                            nach Server-Aktivität · Europe/Berlin
                        </p>
                    </div>

                    <ul class="mt-3 grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <li v-for="game in recent" :key="game.slug">
                            <Card
                                class="flex h-full flex-col"
                                data-test="recent-game-card"
                            >
                                <CardContent
                                    class="flex flex-1 flex-col gap-2 pt-4"
                                >
                                    <div>
                                        <Link
                                            :href="`/games/${game.slug}`"
                                            class="text-sm font-medium outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring"
                                        >
                                            {{ game.title }}
                                        </Link>
                                        <time
                                            :datetime="game.last_played_at"
                                            :title="
                                                absoluteTime(
                                                    game.last_played_at,
                                                )
                                            "
                                            class="mt-0.5 block font-mono text-[10px] text-muted-foreground"
                                        >
                                            {{
                                                relativeTime(
                                                    game.last_played_at,
                                                )
                                            }}
                                        </time>
                                    </div>

                                    <p
                                        v-if="game.last_played_server"
                                        class="flex flex-wrap items-center gap-x-2 gap-y-1 font-mono text-[11px] text-muted-foreground"
                                    >
                                        <Server
                                            class="size-3 shrink-0 text-live"
                                            aria-hidden="true"
                                        />
                                        <span
                                            class="max-w-full truncate text-foreground"
                                            >{{
                                                game.last_played_server.title
                                            }}</span
                                        >
                                        <span
                                            v-if="
                                                game.last_played_server.map !==
                                                ''
                                            "
                                        >
                                            · {{ game.last_played_server.map }}
                                        </span>
                                        <span>
                                            ·
                                            {{
                                                game.last_played_server.players
                                            }}/{{
                                                game.last_played_server
                                                    .max_players
                                            }}
                                            Spieler
                                        </span>
                                    </p>
                                    <p
                                        v-else
                                        class="text-xs text-muted-foreground"
                                    >
                                        Server zuletzt aktiv — gerade nicht
                                        besetzt.
                                    </p>

                                    <div
                                        class="mt-auto flex flex-wrap gap-2 pt-1"
                                    >
                                        <Button
                                            as-child
                                            size="sm"
                                            class="flex-1"
                                            :variant="
                                                game.installable
                                                    ? 'default'
                                                    : 'outline'
                                            "
                                        >
                                            <a
                                                v-if="
                                                    !game.installable &&
                                                    game.play_url
                                                "
                                                :href="game.play_url"
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <MonitorPlay
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                Spielen
                                            </a>
                                            <Link
                                                v-else
                                                :href="installHref(game)"
                                            >
                                                <Download
                                                    v-if="game.installable"
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                {{
                                                    game.installable
                                                        ? 'Installieren'
                                                        : 'Details'
                                                }}
                                            </Link>
                                        </Button>
                                        <Button
                                            v-if="
                                                game.installable &&
                                                game.play_url
                                            "
                                            as-child
                                            size="sm"
                                            variant="outline"
                                        >
                                            <a
                                                :href="game.play_url"
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                Spielen
                                            </a>
                                        </Button>
                                    </div>

                                    <!-- With a published build the button above
                                         opens the launcher; the deep link is the
                                         follow-up for a PC that already has it. -->
                                    <p
                                        v-if="launcherPrimary(game)"
                                        class="text-xs leading-relaxed text-muted-foreground"
                                    >
                                        Launcher schon da?
                                        <a
                                            :href="game.install_deeplink"
                                            class="inline-block font-medium whitespace-nowrap text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                        >
                                            Im Launcher öffnen
                                        </a>
                                    </p>
                                </CardContent>
                            </Card>
                        </li>
                    </ul>
                </section>

                <p
                    v-if="games.length === 0 && !filters.only_lan"
                    class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                    data-test="empty-library"
                >
                    Noch keine Spiele in der Bibliothek. Die Orga pflegt sie
                    über die Rezepte.
                    <span class="mt-2 block" data-test="share-invite">
                        Dein Spiel fehlt?
                        <Link
                            href="/games/share"
                            class="font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Teile es mit der LAN.
                        </Link>
                    </span>
                </p>
                <p
                    v-else-if="filtered.length === 0"
                    class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                    data-test="empty-filter"
                >
                    <template v-if="onlyLan">
                        Auf der LAN liegt noch kein Spiel. Schalte „Nur
                        LAN-Spiele“ aus, um die ganze Bibliothek zu sehen.
                    </template>
                    <template v-else>
                        Kein Spiel passt.
                        {{
                            query.trim() !== '' ? 'Ändere den Suchbegriff,' : ''
                        }}
                        oder frag die Orga, ob die Dateien schon auf der LAN
                        liegen.
                    </template>
                    <span class="mt-2 block" data-test="share-invite">
                        Dein Spiel fehlt?
                        <Link
                            href="/games/share"
                            class="font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >
                            Teile es mit der LAN.
                        </Link>
                    </span>
                </p>

                <div
                    v-else
                    class="mt-5 grid gap-4 sm:grid-cols-2 xl:grid-cols-3"
                >
                    <Card
                        v-for="game in filtered"
                        :key="game.slug"
                        class="flex flex-col"
                        data-test="game-card"
                    >
                        <CardHeader>
                            <div class="flex items-start justify-between gap-2">
                                <CardTitle class="text-lg">
                                    <Link
                                        :href="`/games/${game.slug}`"
                                        class="outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring"
                                    >
                                        {{ game.title }}
                                    </Link>
                                </CardTitle>
                                <Badge
                                    v-if="game.lan_ready"
                                    variant="outline"
                                    class="border-live/40 font-mono text-[10px] text-live"
                                >
                                    LAN
                                </Badge>
                            </div>
                            <CardDescription
                                class="font-mono text-[11px] tracking-wide"
                            >
                                {{ platformLabel(game.platforms) }}
                                <span v-if="game.version">
                                    · {{ game.version }}</span
                                >
                            </CardDescription>
                        </CardHeader>
                        <CardContent class="flex flex-1 flex-col gap-3">
                            <p
                                class="line-clamp-3 text-sm text-muted-foreground"
                            >
                                {{ game.summary }}
                            </p>

                            <p
                                v-if="game.server_note"
                                class="font-mono text-[11px] text-muted-foreground"
                                data-test="game-server-note"
                            >
                                {{ game.server_note }}
                            </p>

                            <div class="flex flex-wrap gap-2 text-xs">
                                <Badge
                                    v-if="game.running_tournaments_count > 0"
                                    variant="outline"
                                    class="border-primary/40 text-primary"
                                >
                                    <Trophy class="size-3" aria-hidden="true" />
                                    Turnier läuft
                                </Badge>
                                <Badge
                                    v-if="game.server_count > 0"
                                    variant="outline"
                                    class="border-live/40 text-live"
                                >
                                    <Server class="size-3" aria-hidden="true" />
                                    {{ game.server_count }} Server
                                </Badge>
                                <Badge
                                    v-if="game.open_lfg > 0"
                                    variant="outline"
                                    class="border-border text-muted-foreground"
                                >
                                    <Users class="size-3" aria-hidden="true" />
                                    {{ game.open_lfg }} suchen
                                </Badge>
                                <Badge
                                    v-if="!game.supports_multiplayer"
                                    variant="outline"
                                    class="border-border text-muted-foreground"
                                >
                                    Solo
                                </Badge>
                            </div>

                            <div class="mt-auto flex flex-wrap gap-2">
                                <Button
                                    as-child
                                    size="sm"
                                    class="flex-1"
                                    :variant="
                                        game.installable ? 'default' : 'outline'
                                    "
                                >
                                    <a
                                        v-if="
                                            !game.installable && game.play_url
                                        "
                                        :href="game.play_url"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        <MonitorPlay
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Spielen
                                    </a>
                                    <Link v-else :href="installHref(game)">
                                        <Download
                                            v-if="game.installable"
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        {{
                                            game.installable
                                                ? 'Installieren'
                                                : 'Details'
                                        }}
                                    </Link>
                                </Button>
                                <Button
                                    v-if="game.installable && game.play_url"
                                    as-child
                                    size="sm"
                                    variant="outline"
                                >
                                    <a
                                        :href="game.play_url"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Spielen
                                    </a>
                                </Button>
                            </div>

                            <!-- The primary install action opens the launcher
                                 when a build is published, so a fresh PC never
                                 fires `lanomat://` into nothing. The deep link
                                 stays the follow-up for a PC that has it. -->
                            <p
                                v-if="launcherPrimary(game)"
                                class="text-xs leading-relaxed text-muted-foreground"
                                data-test="card-launcher"
                            >
                                Launcher schon da?
                                <a
                                    :href="game.install_deeplink"
                                    class="inline-block font-medium whitespace-nowrap text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                                >
                                    Im Launcher öffnen
                                </a>
                            </p>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </PageContainer>
</template>
