<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import LauncherLink from '@/components/LauncherLink.vue';
import { Button } from '@/components/ui/button';
import type { ServerInstallDto } from '@/types';

/**
 * The recipe actions for one server row: join through the launcher (which
 * installs the game when it is missing and starts it on this server), open the
 * game's page to install it, or find people to play with. The row's own amber
 * join action stays the primary; everything here is outline/ghost.
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
    <div
        v-if="pageUrl"
        class="flex flex-wrap items-center gap-x-3 gap-y-2"
        data-test="server-install-actions"
    >
        <div
            v-if="launcherHref"
            class="flex min-w-0 flex-col items-start gap-1"
        >
            <LauncherLink
                :href="launcherHref"
                size="sm"
                variant="outline"
                class="pointer-coarse:min-h-11"
                manual-hint="Die Serveradresse auf dieser Seite bleibt gültig — du kannst auch von Hand beitreten."
            >
                {{ labels.install_launcher }}
            </LauncherLink>
            <p v-if="!compact" class="max-w-xs text-xs text-muted-foreground">
                {{ labels.install_launcher_hint }}
            </p>
        </div>

        <Button
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
            v-if="!compact"
            :href="playersHref ?? pageUrl"
            class="inline-flex min-h-8 items-center rounded-sm px-1 text-sm text-muted-foreground underline-offset-4 outline-none hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
        >
            {{ labels.install_find_players }}
        </Link>
    </div>
</template>
