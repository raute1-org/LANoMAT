<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import ServerCard from '@/components/servers/ServerCard.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { permanent as serversPermanent } from '@/routes/servers';
import type { GlobalServerRowDto } from '@/types';

/**
 * The global server browser: one answer to "which servers are running?" across
 * every game — the event lists of all public events plus the permanent servers,
 * grouped by game. Filters live server-side (`?game=`, `?q=`) so a filtered
 * view is a real, linkable URL; the visits are partial (only the list props),
 * which keeps the controls, scroll position and focus while typing.
 */
const props = defineProps<{
    servers: GlobalServerRowDto[];
    /** Game names of the unfiltered list, for the game filter. */
    games: string[];
    filters: { game: string; q: string; active: boolean };
    labels: Record<string, string>;
}>();

// Only full GET visits count for loading/error; the filter visits are partial
// reloads and must not swap the page for skeletons (see usePageLoadState).
const { isNavigating, hasError } = usePageLoadState();

const game = ref(props.filters.game);
const q = ref(props.filters.q);

const selectClass =
    'h-11 w-full rounded-md border border-border bg-background px-3 text-base text-foreground focus-visible:border-ring focus-visible:ring-2 focus-visible:ring-ring/50 focus-visible:outline-none sm:h-9 sm:text-sm';

let debounceHandle: ReturnType<typeof setTimeout> | undefined;
let refreshTimer: ReturnType<typeof setInterval> | undefined;

function applyFilters() {
    clearTimeout(debounceHandle);

    router.get(
        '/servers',
        {
            ...(game.value !== '' ? { game: game.value } : {}),
            ...(q.value.trim() !== '' ? { q: q.value.trim() } : {}),
        },
        {
            only: ['servers', 'games', 'filters'],
            preserveState: true,
            preserveScroll: true,
            replace: true,
        },
    );
}

function onSearchInput() {
    clearTimeout(debounceHandle);
    debounceHandle = setTimeout(applyFilters, 350);
}

function resetFilters() {
    game.value = '';
    q.value = '';
    applyFilters();
}

// Player counts and startup state change without any navigation: refresh only
// the list now and then while the tab is visible, keeping the current filter.
function refreshServers() {
    if (document.visibilityState === 'visible' && !isNavigating.value) {
        router.reload({ only: ['servers'] });
    }
}

onMounted(() => {
    refreshTimer = setInterval(refreshServers, 20000);
});

onUnmounted(() => {
    clearTimeout(debounceHandle);
    clearInterval(refreshTimer);
});

type ServerGroup = { key: string; name: string; rows: GlobalServerRowDto[] };

const groups = computed<ServerGroup[]>(() => {
    const byGame = new Map<string, GlobalServerRowDto[]>();

    for (const server of props.servers) {
        const key = server.game ?? '';
        const rows = byGame.get(key) ?? [];
        rows.push(server);
        byGame.set(key, rows);
    }

    return [...byGame.entries()]
        .map(([key, rows]) => ({ key, name: key, rows }))
        .sort((a, b) => {
            // A server without a game name is a rare fallback; keep it last.
            if (a.key === '') {
                return 1;
            }

            if (b.key === '') {
                return -1;
            }

            return a.name.localeCompare(b.name, 'de');
        });
});

const resultCount = computed(() =>
    props.labels.result_count.replace(':count', String(props.servers.length)),
);
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer
        width="wide"
        :title="labels.title"
        :description="labels.intro"
    >
        <template #actions>
            <Button as-child variant="outline" size="sm">
                <a :href="serversPermanent().url">{{
                    labels.permanent_badge
                }}</a>
            </Button>
        </template>

        <!-- loading: only a full visit, e.g. entering the page again -->
        <div v-if="isNavigating" class="mt-8 space-y-4">
            <Skeleton class="h-16 w-full rounded-md" />
            <Skeleton class="h-48 w-full rounded-md" />
        </div>

        <!-- error -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">{{ labels.error }}</p>
        </div>

        <template v-else>
            <!-- filters: game + free-text search, both server-side -->
            <form
                class="mt-8 flex flex-col gap-3 sm:flex-row sm:items-end"
                role="search"
                @submit.prevent="applyFilters"
            >
                <label class="block sm:w-56">
                    <span
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        {{ labels.filter_game_label }}
                    </span>
                    <select
                        v-model="game"
                        :class="selectClass"
                        @change="applyFilters"
                    >
                        <option value="">
                            {{ labels.filter_all_games }}
                        </option>
                        <option v-for="name in games" :key="name" :value="name">
                            {{ name }}
                        </option>
                    </select>
                </label>

                <label class="block min-w-0 flex-1">
                    <span
                        class="mb-1 block text-xs font-medium text-muted-foreground"
                    >
                        {{ labels.filter_search_label }}
                    </span>
                    <Input
                        v-model="q"
                        type="search"
                        :placeholder="labels.filter_search_placeholder"
                        :aria-label="labels.filter_search_label"
                        @input="onSearchInput"
                        @keydown.enter.prevent="applyFilters"
                    />
                </label>

                <Button
                    v-if="filters.active"
                    type="button"
                    variant="outline"
                    @click="resetFilters"
                >
                    {{ labels.filter_reset }}
                </Button>
            </form>

            <!-- empty: nothing running at all -->
            <div
                v-if="servers.length === 0 && !filters.active"
                class="mt-8 rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
            >
                {{ labels.empty }}
            </div>

            <!-- empty: nothing matches the filter -->
            <div
                v-else-if="servers.length === 0"
                class="mt-8 rounded-md border border-dashed border-border p-6 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty_filtered }}
                </p>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    class="mt-3"
                    @click="resetFilters"
                >
                    {{ labels.filter_reset }}
                </Button>
            </div>

            <!-- normal: grouped by game -->
            <template v-else>
                <p
                    class="mt-6 font-mono text-xs text-muted-foreground tabular-nums"
                >
                    {{ resultCount }}
                </p>

                <section
                    v-for="(group, index) in groups"
                    :key="group.key"
                    class="mt-6"
                    :aria-labelledby="`server-group-${index}`"
                >
                    <h2
                        :id="`server-group-${index}`"
                        class="text-lg font-semibold tracking-tight text-foreground"
                    >
                        {{ group.name || labels.unknown_game }}
                    </h2>
                    <p class="text-xs text-muted-foreground">
                        {{
                            labels.result_count.replace(
                                ':count',
                                String(group.rows.length),
                            )
                        }}
                    </p>

                    <div class="mt-3 grid gap-4 lg:grid-cols-2">
                        <ServerCard
                            v-for="server in group.rows"
                            :key="server.id"
                            :server="server"
                            :labels="labels"
                        />
                    </div>
                </section>
            </template>
        </template>
    </PageContainer>
</template>
