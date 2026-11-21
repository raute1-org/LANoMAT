<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import {
    ClipboardCopy,
    Download,
    ExternalLink,
    Gamepad2,
    Headphones,
    MonitorPlay,
    Play,
    Plus,
    Server,
    Trophy,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { toast } from 'vue-sonner';
import GamesSidebar from '@/components/games/GamesSidebar.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Separator } from '@/components/ui/separator';

type ServerRow = {
    id: number;
    title: string | null;
    game: string | null;
    matchLabel: string | null;
    address: string;
    port: number | null;
    connectString: string;
    connectIsLink: boolean;
    status: string;
    query: {
        serverName: string;
        map: string;
        players: number;
        maxPlayers: number;
    } | null;
    event: string | null;
};

type Game = {
    slug: string;
    title: string;
    summary: string | null;
    description: string | null;
    version: string | null;
    platforms: string[] | null;
    homepage: string | null;
    avatar_converter_url: string | null;
    license: string | null;
    requirements: {
        key: string;
        role: string;
        label: string;
        required: boolean;
        match: string[];
        available_on_lan: boolean;
        name: string | null;
        size_bytes: number | null;
    }[];
    steps: {
        id: string;
        title: string;
        os: string;
        optional: boolean;
        detail: string;
    }[];
    avatars: number;
    play_url: string | null;
    /** "Ein Server · Nickname · Join/Leave" for a browser recipe. */
    server_note: string | null;
    launch_target: string | null;
    supports_multiplayer: boolean;
    lan_ready: boolean;
    installable: boolean;
    requires_agent: boolean;
    server_count: number;
    running_tournaments_count: number;
    install_command: string;
    launch_command: string;
    install_deeplink: string;
    launch_deeplink: string;
    launcher_available: boolean;
    launcher_supported: boolean;
    launcher_url: string | null;
};

const props = defineProps<{
    game: Game;
    servers: ServerRow[];
    tournaments: {
        id: number;
        name: string;
        status: string;
        game: string | null;
        starts_at: string;
        url: string;
    }[];
    lfg: {
        id: number;
        title: string;
        body: string;
        slots_needed: number;
        author: string | null;
        expires_at: string;
    }[];
    library: {
        slug: string;
        title: string;
        platforms: string[] | null;
        summary: string | null;
        lan_ready: boolean;
    }[];
    active: string;
    active_event: { slug: string; name: string } | null;
    voice_url: string | null;
    game_modules: { game_servers: boolean; web_voice: boolean; lfg: boolean };
}>();

const readyServers = computed(() =>
    props.servers.filter((server) =>
        ['ready', 'live', 'warmup'].includes(server.status),
    ),
);

/** "1 Avatar zur Auswahl" / "3 Avatare zur Auswahl" — never "1 Avatare". */
const avatarLabel = computed<string>(() =>
    props.game.avatars === 1
        ? '1 Avatar zur Auswahl'
        : `${props.game.avatars} Avatare zur Auswahl`,
);

const platformLabel = (platforms: string[] | null): string =>
    (platforms ?? [])
        .map((p) =>
            p === 'windows' ? 'Windows' : p === 'linux' ? 'Linux' : 'macOS',
        )
        .join(' · ');

const formatBytes = (bytes: number | null): string => {
    if (bytes === null) {
        return '';
    }

    const units = ['B', 'KB', 'MB', 'GB', 'TB'];
    let value = bytes;
    let unit = 0;

    while (value >= 1024 && unit < units.length - 1) {
        value /= 1024;
        unit++;
    }

    return `${value.toFixed(value >= 10 || unit === 0 ? 0 : 1)} ${units[unit]}`;
};

async function copy(value: string, label: string): Promise<void> {
    try {
        await navigator.clipboard.writeText(value);
        toast.success(label);
    } catch {
        toast.error('Konnte nicht kopieren — bitte manuell markieren.');
    }
}

function announceRound(): void {
    router.post(`/games/${props.game.slug}/lfg`, {}, { preserveScroll: true });
}
</script>

<template>
    <Head :title="game.title" />

    <PageContainer width="wide">
        <div class="grid gap-8 lg:grid-cols-[16rem_1fr]">
            <GamesSidebar :games="library" :active="active" />

            <div class="min-w-0">
                <header
                    class="flex flex-wrap items-start justify-between gap-4"
                >
                    <div class="min-w-0">
                        <p
                            class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                        >
                            Spiel · {{ game.slug }}
                        </p>
                        <h1 class="mt-1 text-3xl font-semibold tracking-tight">
                            {{ game.title }}
                        </h1>
                        <p class="mt-2 max-w-2xl text-sm text-muted-foreground">
                            {{ game.summary }}
                        </p>
                        <p
                            v-if="game.server_note"
                            class="mt-1 font-mono text-xs text-muted-foreground"
                            data-test="game-server-note"
                        >
                            {{ game.server_note }}
                        </p>
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <Badge
                                v-if="game.lan_ready"
                                variant="outline"
                                class="border-live/40 font-mono text-[10px] text-live"
                            >
                                ● AUF DER LAN
                            </Badge>
                            <Badge
                                v-else
                                variant="outline"
                                class="border-border font-mono text-[10px] text-muted-foreground"
                                data-test="not-lan-ready"
                            >
                                NOCH NICHT AUF DER LAN
                            </Badge>
                            <Badge
                                variant="outline"
                                class="font-mono text-[10px]"
                            >
                                {{ platformLabel(game.platforms) }}
                            </Badge>
                            <Badge
                                v-if="game.version"
                                variant="outline"
                                class="font-mono text-[10px]"
                            >
                                v{{ game.version }}
                            </Badge>
                            <Badge
                                v-if="game.running_tournaments_count > 0"
                                variant="outline"
                                class="border-primary/40 font-mono text-[10px] text-primary"
                            >
                                Turnier läuft
                            </Badge>
                            <Badge
                                v-if="!game.supports_multiplayer"
                                variant="outline"
                                class="border-border font-mono text-[10px] text-muted-foreground"
                            >
                                Solo
                            </Badge>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <!-- A fresh PC has no `lanomat://` handler; for the one
                             recipe the pilot build installs, the primary action
                             opens the launcher download. Every other recipe
                             keeps the agent deep link. -->
                        <Button
                            v-if="game.installable"
                            as-child
                            data-test="install"
                        >
                            <Link
                                v-if="
                                    game.launcher_supported &&
                                    game.launcher_available &&
                                    game.launcher_url
                                "
                                :href="game.launcher_url"
                            >
                                <Download class="size-4" aria-hidden="true" />
                                Installieren
                            </Link>
                            <a v-else :href="game.install_deeplink">
                                <Download class="size-4" aria-hidden="true" />
                                Installieren
                            </a>
                        </Button>
                        <Button
                            v-if="
                                game.installable &&
                                game.launcher_supported &&
                                game.launcher_available &&
                                game.launcher_url
                            "
                            as-child
                            variant="ghost"
                            data-test="install-launcher"
                        >
                            <a :href="game.install_deeplink">
                                <ExternalLink
                                    class="size-4"
                                    aria-hidden="true"
                                />
                                Im Launcher öffnen
                            </a>
                        </Button>
                        <Button
                            v-if="game.play_url"
                            as-child
                            variant="outline"
                            data-test="launch-web"
                        >
                            <a
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
                        </Button>
                        <Button
                            v-else-if="game.requires_agent"
                            as-child
                            variant="outline"
                            data-test="launch"
                        >
                            <a :href="game.launch_deeplink">
                                <Play class="size-4" aria-hidden="true" />
                                Starten
                            </a>
                        </Button>
                    </div>
                </header>

                <div
                    v-if="game.installable || game.requires_agent"
                    class="mt-4 flex flex-wrap gap-2 text-xs"
                >
                    <Button
                        v-if="game.installable"
                        type="button"
                        size="sm"
                        variant="outline"
                        class="h-auto max-w-full text-left whitespace-normal"
                        @click="
                            copy(
                                game.install_command,
                                'Installationsbefehl kopiert',
                            )
                        "
                    >
                        <ClipboardCopy class="size-4" aria-hidden="true" />
                        <span class="font-mono break-all">{{
                            game.install_command
                        }}</span>
                    </Button>
                    <Button
                        v-if="game.requires_agent"
                        type="button"
                        size="sm"
                        variant="ghost"
                        class="h-auto max-w-full text-left whitespace-normal"
                        @click="
                            copy(game.launch_command, 'Startbefehl kopiert')
                        "
                    >
                        <span
                            class="font-mono break-all text-muted-foreground"
                            >{{ game.launch_command }}</span
                        >
                    </Button>
                </div>
                <p
                    v-else
                    class="mt-4 text-xs text-muted-foreground"
                    data-test="browser-only"
                >
                    Kein Installer nötig — {{ game.title }} läuft direkt im
                    Browser. Der Agent ist optional.
                </p>

                <!-- The deep link only fires once the launcher is installed;
                     this explains why the primary action leads to the download
                     on a fresh PC (pilot recipe plus a published build). -->
                <p
                    v-if="
                        game.requires_agent &&
                        game.launcher_supported &&
                        game.launcher_available &&
                        game.launcher_url
                    "
                    class="mt-2 max-w-2xl text-xs leading-relaxed text-muted-foreground"
                    data-test="launcher-download"
                >
                    Noch kein Launcher auf diesem PC? „Installieren“ führt zum
                    <Link
                        :href="game.launcher_url"
                        class="font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    >
                        Launcher-Download</Link
                    >
                    — der Ein-Klick-App, die {{ game.title }} holt, entpackt und
                    startet. Ist der Launcher schon da, geht es über „Im
                    Launcher öffnen“ direkt weiter.
                </p>

                <Separator class="my-8" />

                <div class="grid grid-cols-1 gap-6 xl:grid-cols-[1fr_20rem]">
                    <div class="space-y-6">
                        <!-- running tournaments -->
                        <Card v-if="tournaments.length > 0">
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                >
                                    <Trophy
                                        class="size-4 text-primary"
                                        aria-hidden="true"
                                    />
                                    Laufende Turniere
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-2">
                                <a
                                    v-for="tournament in tournaments"
                                    :key="tournament.id"
                                    :href="tournament.url"
                                    class="flex items-center justify-between gap-3 rounded-sm border border-border px-3 py-2 text-sm transition-colors hover:bg-muted"
                                >
                                    <span class="min-w-0 truncate">{{
                                        tournament.name
                                    }}</span>
                                    <span
                                        class="font-mono text-xs text-primary uppercase"
                                    >
                                        {{ tournament.status }}
                                    </span>
                                </a>
                            </CardContent>
                        </Card>

                        <!-- servers -->
                        <Card v-if="game_modules.game_servers">
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                >
                                    <Server
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Server
                                </CardTitle>
                            </CardHeader>
                            <CardContent>
                                <div
                                    v-if="servers.length === 0"
                                    class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                                    data-test="no-servers"
                                >
                                    Kein Server aktiv.
                                    <span v-if="game.supports_multiplayer">
                                        Installiere das Spiel und starte eine
                                        Runde — oder frag die Orga nach einem
                                        Server.
                                    </span>
                                    <span v-else>
                                        {{ game.title }} ist im LAN nur solo/am
                                        eigenen PC spielbar.
                                    </span>
                                </div>
                                <ul v-else class="divide-y divide-border">
                                    <li
                                        v-for="server in servers"
                                        :key="server.id"
                                        class="flex flex-wrap items-center justify-between gap-3 py-3"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="flex items-center gap-2 truncate text-sm font-medium"
                                            >
                                                <span
                                                    class="size-1.5 rounded-full"
                                                    :class="
                                                        readyServers.includes(
                                                            server,
                                                        )
                                                            ? 'bg-live'
                                                            : 'bg-muted-foreground'
                                                    "
                                                />
                                                {{
                                                    server.title ??
                                                    server.query?.serverName ??
                                                    server.game
                                                }}
                                            </p>
                                            <p
                                                class="font-mono text-xs text-muted-foreground"
                                            >
                                                {{ server.connectString }}
                                                <span v-if="server.query">
                                                    · {{ server.query.map }} ·
                                                    {{
                                                        server.query.players
                                                    }}/{{
                                                        server.query.maxPlayers
                                                    }}
                                                </span>
                                            </p>
                                        </div>
                                        <Button
                                            as-child
                                            size="sm"
                                            variant="outline"
                                        >
                                            <a
                                                :href="
                                                    server.connectIsLink
                                                        ? server.connectString
                                                        : `steam://connect/${server.connectString}`
                                                "
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <ExternalLink
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                Joinen
                                            </a>
                                        </Button>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <!-- lfg -->
                        <Card v-if="game_modules.lfg">
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                >
                                    <Users
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Zusammen spielen
                                </CardTitle>
                            </CardHeader>
                            <CardContent class="space-y-3">
                                <div class="flex flex-wrap gap-2">
                                    <Button
                                        v-if="active_event"
                                        size="sm"
                                        type="button"
                                        @click="announceRound"
                                    >
                                        <Plus
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Runde ankündigen
                                    </Button>
                                    <Button
                                        v-if="
                                            voice_url && game_modules.web_voice
                                        "
                                        as-child
                                        size="sm"
                                        variant="outline"
                                    >
                                        <a :href="voice_url">
                                            <Headphones
                                                class="size-4"
                                                aria-hidden="true"
                                            />
                                            Sprachchat
                                        </a>
                                    </Button>
                                </div>
                                <p
                                    v-if="!active_event"
                                    class="text-sm text-muted-foreground"
                                    data-test="lfg-no-event"
                                >
                                    Kein Event aktiv — Runden lassen sich erst
                                    ankündigen, wenn die LAN läuft.
                                </p>
                                <p
                                    v-else-if="lfg.length === 0"
                                    class="text-sm text-muted-foreground"
                                >
                                    Noch keine Runde angekündigt. Ein Klick
                                    reicht — die anderen sehen es sofort.
                                </p>
                                <ul v-else class="divide-y divide-border">
                                    <li
                                        v-for="post in lfg"
                                        :key="post.id"
                                        class="py-2"
                                    >
                                        <p class="text-sm font-medium">
                                            {{ post.title }}
                                        </p>
                                        <p
                                            class="font-mono text-xs text-muted-foreground"
                                        >
                                            {{ post.author }} · sucht
                                            {{ post.slots_needed }}
                                        </p>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>

                        <!-- requirements -->
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base">Dateien</CardTitle>
                            </CardHeader>
                            <CardContent>
                                <p
                                    v-if="game.requirements.length === 0"
                                    class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                                    data-test="no-files"
                                >
                                    Keine Dateien nötig.
                                    <span v-if="game.play_url">
                                        {{ game.title }} läuft direkt im
                                        Browser.</span
                                    >
                                    <span v-else>
                                        Alles Weitere steht in der
                                        Installationsanleitung.</span
                                    >
                                </p>
                                <ul v-else class="divide-y divide-border">
                                    <li
                                        v-for="requirement in game.requirements"
                                        :key="requirement.key"
                                        class="flex flex-wrap items-center justify-between gap-2 py-2"
                                    >
                                        <div class="min-w-0">
                                            <p class="truncate text-sm">
                                                {{ requirement.label }}
                                                <span
                                                    v-if="requirement.required"
                                                    class="text-destructive"
                                                    >*</span
                                                >
                                            </p>
                                            <p
                                                class="font-mono text-[11px] text-muted-foreground"
                                            >
                                                {{
                                                    requirement.name ??
                                                    requirement.match.join(', ')
                                                }}
                                                <span
                                                    v-if="
                                                        requirement.size_bytes
                                                    "
                                                >
                                                    ·
                                                    {{
                                                        formatBytes(
                                                            requirement.size_bytes,
                                                        )
                                                    }}
                                                </span>
                                            </p>
                                        </div>
                                        <Badge
                                            variant="outline"
                                            :class="
                                                requirement.available_on_lan
                                                    ? 'border-live/40 text-live'
                                                    : 'border-border text-muted-foreground'
                                            "
                                            class="font-mono text-[10px]"
                                        >
                                            {{
                                                requirement.available_on_lan
                                                    ? 'LAN'
                                                    : 'fehlt'
                                            }}
                                        </Badge>
                                    </li>
                                </ul>
                            </CardContent>
                        </Card>
                    </div>

                    <aside class="space-y-4">
                        <Card>
                            <CardHeader>
                                <CardTitle class="text-base"
                                    >Installation</CardTitle
                                >
                            </CardHeader>
                            <CardContent
                                class="space-y-2 text-sm text-muted-foreground"
                            >
                                <template v-if="game.requires_agent">
                                    <p v-if="game.installable">
                                        Der Agent installiert das Spiel auf
                                        deinem PC und lädt die Dateien direkt
                                        aus dem LAN.
                                    </p>
                                    <p v-else>
                                        Der Agent startet das Spiel aus deinem
                                        bestehenden Install — eine Installation
                                        ist nicht nötig.
                                    </p>
                                    <ol
                                        v-if="game.steps.length > 0"
                                        class="list-decimal space-y-1 pl-4 font-mono text-xs"
                                    >
                                        <li
                                            v-for="step in game.steps"
                                            :key="step.id"
                                            :class="
                                                step.optional
                                                    ? 'text-muted-foreground/70'
                                                    : ''
                                            "
                                        >
                                            {{ step.title }}
                                            <span v-if="step.optional">
                                                (optional)</span
                                            >
                                        </li>
                                    </ol>
                                </template>
                                <template v-else>
                                    <p data-test="no-install">
                                        Kein Installer nötig:
                                        {{ game.title }} läuft direkt im
                                        Browser. Ein Agent ist nicht
                                        erforderlich.
                                    </p>
                                    <a
                                        v-if="game.play_url"
                                        :href="game.play_url"
                                        target="_blank"
                                        rel="noreferrer"
                                        class="inline-flex items-center gap-1 font-mono text-xs text-primary hover:underline"
                                    >
                                        {{ game.play_url }}
                                        <ExternalLink
                                            class="size-3"
                                            aria-hidden="true"
                                        />
                                    </a>
                                </template>
                                <div
                                    v-if="
                                        game.avatars > 0 ||
                                        game.avatar_converter_url
                                    "
                                    class="space-y-2"
                                >
                                    <div
                                        class="flex flex-wrap items-center gap-2"
                                    >
                                        <p
                                            v-if="game.avatars > 0"
                                            class="font-mono text-xs text-live"
                                        >
                                            {{ avatarLabel }}
                                        </p>
                                        <Button
                                            v-if="game.avatar_converter_url"
                                            as-child
                                            size="sm"
                                            variant="secondary"
                                            data-test="avatar-converter"
                                        >
                                            <a
                                                :href="
                                                    game.avatar_converter_url
                                                "
                                                target="_blank"
                                                rel="noreferrer"
                                            >
                                                <ExternalLink
                                                    class="size-4"
                                                    aria-hidden="true"
                                                />
                                                Avatare konvertieren
                                            </a>
                                        </Button>
                                    </div>
                                    <p
                                        v-if="game.avatar_converter_url"
                                        class="text-xs text-muted-foreground"
                                    >
                                        VRM wählen → konvertieren → fertiges
                                        UT2004-Modell herunterladen; die
                                        VRM-Dateien kommen aus dem LAN.
                                    </p>
                                </div>
                            </CardContent>
                        </Card>

                        <Card v-if="game.homepage || game.license">
                            <CardHeader>
                                <CardTitle class="text-base"
                                    >Rechtliches</CardTitle
                                >
                            </CardHeader>
                            <CardContent
                                class="space-y-2 text-xs text-muted-foreground"
                            >
                                <p v-if="game.license">{{ game.license }}</p>
                                <a
                                    v-if="game.homepage"
                                    :href="game.homepage"
                                    target="_blank"
                                    rel="noreferrer"
                                    class="inline-flex items-center gap-1 text-primary hover:underline"
                                >
                                    Homepage
                                    <ExternalLink
                                        class="size-3"
                                        aria-hidden="true"
                                    />
                                </a>
                            </CardContent>
                        </Card>

                        <Card v-if="game.description">
                            <CardHeader>
                                <CardTitle
                                    class="flex items-center gap-2 text-base"
                                >
                                    <Gamepad2
                                        class="size-4 text-muted-foreground"
                                        aria-hidden="true"
                                    />
                                    Über das Spiel
                                </CardTitle>
                            </CardHeader>
                            <CardContent
                                class="text-sm whitespace-pre-line text-muted-foreground"
                            >
                                {{ game.description }}
                            </CardContent>
                        </Card>
                    </aside>
                </div>
            </div>
        </div>
    </PageContainer>
</template>
