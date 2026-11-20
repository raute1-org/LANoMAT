<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
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
    license: string | null;
    requirements: {
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
    launch_target: string | null;
    supports_multiplayer: boolean;
    lan_ready: boolean;
    install_command: string;
    launch_command: string;
    install_deeplink: string;
    launch_deeplink: string;
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
                        <div class="mt-3 flex flex-wrap items-center gap-2">
                            <Badge
                                v-if="game.lan_ready"
                                variant="outline"
                                class="border-live/40 font-mono text-[10px] text-live"
                            >
                                ● AUF DER LAN
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
                                v-if="tournaments.length > 0"
                                variant="outline"
                                class="border-primary/40 font-mono text-[10px] text-primary"
                            >
                                Turnier läuft
                            </Badge>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-2">
                        <Button as-child data-test="install">
                            <a :href="game.install_deeplink">
                                <Download class="size-4" aria-hidden="true" />
                                Installieren
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
                                Im Browser starten
                            </a>
                        </Button>
                        <Button
                            v-else
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

                <div class="mt-4 flex flex-wrap gap-2 text-xs">
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="
                            copy(
                                game.install_command,
                                'Installationsbefehl kopiert',
                            )
                        "
                    >
                        <ClipboardCopy class="size-4" aria-hidden="true" />
                        <span class="font-mono">{{
                            game.install_command
                        }}</span>
                    </Button>
                    <Button
                        type="button"
                        size="sm"
                        variant="ghost"
                        @click="
                            copy(game.launch_command, 'Startbefehl kopiert')
                        "
                    >
                        <span class="font-mono text-muted-foreground">{{
                            game.launch_command
                        }}</span>
                    </Button>
                </div>

                <Separator class="my-8" />

                <div class="grid gap-6 xl:grid-cols-[1fr_20rem]">
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
                        <Card>
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
                                    v-if="lfg.length === 0"
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
                                <ul class="divide-y divide-border">
                                    <li
                                        v-for="requirement in game.requirements"
                                        :key="requirement.label"
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
                                <p>
                                    Der Agent installiert das Spiel auf deinem
                                    PC und lädt die Dateien direkt aus dem LAN.
                                </p>
                                <ol
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
                                <p
                                    v-if="game.avatars > 0"
                                    class="font-mono text-xs text-live"
                                >
                                    {{ game.avatars }} Avatare zur Auswahl
                                </p>
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
