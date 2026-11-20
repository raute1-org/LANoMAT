<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Gamepad2 } from '@lucide/vue';
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
                class="group flex items-start gap-2 rounded-sm border border-transparent px-2 py-2 text-sm transition-colors outline-none hover:bg-muted focus-visible:ring-2 focus-visible:ring-ring"
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
    </aside>
</template>
