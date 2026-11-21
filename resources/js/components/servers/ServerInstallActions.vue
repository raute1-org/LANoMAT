<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LauncherLink from '@/components/LauncherLink.vue';
import { Button } from '@/components/ui/button';
import type { ServerInstallDto } from '@/types';

/**
 * The recipe actions for one server row: join through the launcher (which
 * installs the game when it is missing and starts it on this server), open the
 * game's page to install it, or find people to play with. The launcher handoff
 * comes first and stays outline — a server row is not a "Dein Match" row, so
 * none of these carries the amber accent. The one-line hint sits below the
 * action row so the buttons never wrap around a two-line column.
 *
 * Rendered whenever an `install` block exists; each action is guarded on the
 * field it needs (a recipe without a launcher link still has its page, etc.).
 */
const props = defineProps<{
    install?: ServerInstallDto | null;
    /** `gameservers.page` labels; both server surfaces carry them. */
    labels: Record<string, string>;
    /** One quiet game link only, for a row that is already dense. */
    compact?: boolean;
}>();

const pageUrl = computed(() => {
    const url = props.install?.pageUrl;

    return typeof url === 'string' && url !== '' ? url : null;
});

// Only the launcher's own scheme may take over the click; anything else is ignored.
const launcherHref = computed(() => {
    const url = props.install?.launcherJoinUrl;

    return typeof url === 'string' && url.startsWith('lanomat://join/')
        ? url
        : null;
});

const playersHref = computed(() =>
    pageUrl.value === null ? null : `${pageUrl.value}#zusammen-spielen`,
);
</script>

<template>
    <div v-if="install" class="space-y-1" data-test="server-install-actions">
        <div class="flex flex-wrap items-center gap-2">
            <LauncherLink
                v-if="launcherHref"
                :href="launcherHref"
                size="sm"
                variant="outline"
                class="pointer-coarse:min-h-11"
                manual-hint="Die Serveradresse auf dieser Seite bleibt gültig — du kannst auch von Hand beitreten."
            >
                {{ labels.install_launcher }}
            </LauncherLink>

            <Button
                v-if="pageUrl"
                as-child
                size="sm"
                variant="ghost"
                class="pointer-coarse:min-h-11"
            >
                <Link :href="pageUrl">{{
                    compact ? labels.install_game_short : labels.install_game
                }}</Link>
            </Button>

            <Link
                v-if="pageUrl && !compact"
                :href="playersHref ?? pageUrl"
                class="inline-flex min-h-8 items-center rounded-sm px-1 text-sm text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
            >
                {{ labels.install_find_players }}
            </Link>
        </div>

        <p
            v-if="launcherHref && !compact"
            class="max-w-xs text-xs text-muted-foreground"
        >
            {{ labels.install_launcher_hint }}
        </p>
    </div>
</template>
