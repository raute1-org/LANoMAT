<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Gamepad2, Share2 } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';

type LibraryGame = {
    slug: string;
    title: string;
    platforms: string[] | null;
    summary: string | null;
    lan_ready: boolean;
};

defineProps<{
    games: LibraryGame[];
    active: string;
}>();

const platformLabel = (platforms: string[] | null): string =>
    (platforms ?? [])
        .map((p) =>
            p === 'windows' ? 'Win' : p === 'linux' ? 'Linux' : 'macOS',
        )
        .join(' · ');
</script>

<template>
    <aside class="lg:sticky lg:top-6 lg:self-start" aria-label="Spiele">
        <div class="flex items-center gap-2 px-2 pb-2">
            <Gamepad2 class="size-4 text-muted-foreground" aria-hidden="true" />
            <h2
                class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
            >
                Spiele
            </h2>
        </div>
        <nav class="grid gap-1">
            <Link
                v-for="game in games"
                :key="game.slug"
                :href="`/games/${game.slug}`"
                :aria-current="game.slug === active ? 'page' : undefined"
                class="group flex min-w-0 items-start gap-2 rounded-sm border border-transparent px-2 py-2 text-sm transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
                :class="
                    game.slug === active
                        ? 'border-border bg-card text-foreground'
                        : 'text-muted-foreground'
                "
            >
                <span
                    class="mt-1.5 size-1.5 shrink-0 rounded-full"
                    :class="game.lan_ready ? 'bg-live' : 'bg-border'"
                    :title="
                        game.lan_ready
                            ? 'Auf der LAN verfügbar'
                            : 'Noch nicht auf der LAN'
                    "
                />
                <span class="min-w-0">
                    <span
                        class="block truncate font-medium group-hover:text-foreground"
                    >
                        {{ game.title }}
                    </span>
                    <span
                        class="block truncate font-mono text-[10px] tracking-wide text-muted-foreground"
                    >
                        {{ platformLabel(game.platforms) }}
                    </span>
                </span>
                <Badge
                    v-if="game.lan_ready"
                    variant="outline"
                    class="ml-auto shrink-0 border-live/40 font-mono text-[10px] text-live"
                >
                    LAN
                </Badge>
            </Link>
        </nav>
        <!-- Quiet, not part of the list (it must not read as a fifth game):
             one line below the divider, only on a game's own page (the index
             carries "Spiel teilen" in its header) and only from `lg`, where
             the sidebar is not stacked above the page. -->
        <div
            v-if="active !== ''"
            class="mt-2 hidden border-t border-border px-2 pt-2 lg:block"
        >
            <p class="text-xs text-muted-foreground">Dein Spiel fehlt?</p>
            <Link
                href="/games/share"
                class="mt-1 inline-flex min-h-8 items-center gap-1.5 rounded-sm text-sm text-foreground underline decoration-border underline-offset-2 transition-colors outline-none hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none pointer-coarse:min-h-11"
                data-test="games-share"
            >
                <Share2 class="size-3.5 shrink-0" aria-hidden="true" />
                Spiel teilen
            </Link>
        </div>
    </aside>
</template>
