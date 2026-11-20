<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { Download, Filter, Search, Users } from '@lucide/vue';
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

type GameCard = {
    slug: string;
    title: string;
    summary: string | null;
    platforms: string[] | null;
    version: string | null;
    lan_ready: boolean;
    running_tournaments: {
        id: number;
        name: string;
        status: string;
        url: string;
    }[];
    open_lfg: number;
    play_url: string | null;
    supports_multiplayer: boolean;
};

const props = defineProps<{
    games: GameCard[];
    active_event: { slug: string; name: string } | null;
    modules: { game_servers: boolean; web_voice: boolean; lfg: boolean };
}>();

const query = ref('');
const onlyLan = ref(false);

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

const lanCount = computed(
    () => props.games.filter((game) => game.lan_ready).length,
);

const platformLabel = (platforms: string[] | null): string =>
    (platforms ?? [])
        .map((p) =>
            p === 'windows' ? 'Windows' : p === 'linux' ? 'Linux' : 'macOS',
        )
        .join(' · ');
</script>

<template>
    <Head title="Spiele" />

    <PageContainer
        width="wide"
        title="Spiele"
        description="Was auf dieser LAN läuft — installieren, starten, zusammen spielen."
    >
        <div class="mt-6 grid gap-8 lg:grid-cols-[16rem_1fr]">
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
                        @click="onlyLan = !onlyLan"
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
                    <span v-if="modules.web_voice"> — Sprachchat offen</span>
                </p>

                <p
                    v-if="filtered.length === 0"
                    class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
                >
                    Kein Spiel passt.
                    {{ onlyLan ? 'Schalte „Nur LAN-Spiele“ aus,' : '' }}
                    oder frag die Orga, ob die Dateien schon auf der LAN liegen.
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

                            <div class="flex flex-wrap gap-2 text-xs">
                                <Badge
                                    v-if="game.running_tournaments.length > 0"
                                    variant="outline"
                                    class="border-primary/40 text-primary"
                                >
                                    Turnier läuft
                                </Badge>
                                <Badge
                                    v-if="game.open_lfg > 0"
                                    variant="outline"
                                    class="border-border text-muted-foreground"
                                >
                                    <Users class="size-3" aria-hidden="true" />
                                    {{ game.open_lfg }} suchen
                                </Badge>
                            </div>

                            <div class="mt-auto flex flex-wrap gap-2">
                                <Button as-child size="sm" class="flex-1">
                                    <Link :href="`/games/${game.slug}`">
                                        <Download
                                            class="size-4"
                                            aria-hidden="true"
                                        />
                                        Installieren
                                    </Link>
                                </Button>
                                <Button
                                    v-if="game.play_url"
                                    as-child
                                    size="sm"
                                    variant="outline"
                                >
                                    <a
                                        :href="game.play_url"
                                        target="_blank"
                                        rel="noreferrer"
                                    >
                                        Starten
                                    </a>
                                </Button>
                            </div>
                        </CardContent>
                    </Card>
                </div>
            </section>
        </div>
    </PageContainer>
</template>
