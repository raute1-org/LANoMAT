<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import ServerRow from '@/components/servers/ServerRow.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { index as serversIndex } from '@/routes/servers';
import type { GameServerDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string; isCurrent: boolean } | null;
    /** False when no LAN is current; the way back then has no target. */
    hasCurrent: boolean;
    servers: GameServerDto[];
    permanent: GameServerDto[];
    adminMaps: string[] | null;
    labels: Record<string, string>;
    permanentLabels: Record<string, string>;
    overviewLabels: Record<string, string>;
}>();

// Inertia renders the first load synchronously, so "loading"/"error" only apply
// to a later client-driven reload (see docs/design.md "States").
const { isNavigating, hasError } = usePageLoadState();

// Status and players change without navigation: reload both lists now and then
// while the tab is visible (partial reload, no flash).
let refreshTimer: ReturnType<typeof setInterval> | undefined;

function refreshServers() {
    if (document.visibilityState === 'visible' && !isNavigating.value) {
        router.reload({ only: ['servers', 'permanent', 'adminMaps'] });
    }
}

onMounted(() => {
    refreshTimer = setInterval(refreshServers, 20000);
});

onUnmounted(() => {
    clearInterval(refreshTimer);
});

const ALL = '__all__';
const filter = ref<string>(ALL);
const openId = ref<number | null>(null);

function toggle(id: number) {
    openId.value = openId.value === id ? null : id;
}

const all = computed(() => [...props.servers, ...props.permanent]);

const chips = computed(() => {
    const counts = new Map<string, number>();

    for (const server of all.value) {
        if (server.game) {
            counts.set(server.game, (counts.get(server.game) ?? 0) + 1);
        }
    }

    return [...counts.entries()]
        .sort(([a], [b]) => a.localeCompare(b, 'de'))
        .map(([game, count]) => ({ game, count }));
});

// A server that vanished after a reload must not leave a dead filter behind.
const activeFilter = computed(() =>
    filter.value === ALL || chips.value.some((c) => c.game === filter.value)
        ? filter.value
        : ALL,
);

function matches(server: GameServerDto): boolean {
    return activeFilter.value === ALL || server.game === activeFilter.value;
}

const mine = computed(() =>
    props.servers.filter((s) => s.isMine && matches(s)),
);
const eventRows = computed(() =>
    props.servers.filter((s) => !s.isMine && matches(s)),
);
const permanentRows = computed(() => props.permanent.filter(matches));

const summary = computed(() => {
    const players = all.value.reduce(
        (sum, s) => sum + (s.query?.players ?? 0),
        0,
    );
    const online = all.value.filter((s) => s.status === 'ready').length;

    return props.overviewLabels.summary
        .replace(':players', String(players))
        .replace(':online', String(online))
        .replace(':total', String(all.value.length));
});

const filtered = computed(() => activeFilter.value !== ALL);

function emptyText(kind: 'event' | 'permanent'): string {
    if (filtered.value) {
        return props.overviewLabels.section_empty;
    }

    return kind === 'event'
        ? props.overviewLabels.event_empty
        : props.overviewLabels.permanent_empty;
}
</script>

<template>
    <Head :title="overviewLabels.title" />

    <PageContainer width="wide" :title="overviewLabels.title">
        <template #actions>
            <p
                v-if="all.length > 0"
                class="font-mono text-sm text-muted-foreground tabular-nums"
            >
                {{ summary }}
            </p>
        </template>

        <!-- loading: only mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-6 space-y-2">
            <Skeleton class="h-12 w-full rounded-md" />
            <Skeleton class="h-12 w-full rounded-md" />
            <Skeleton class="h-12 w-full rounded-md" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-6 rounded-md border border-destructive/50 bg-destructive/10 p-8 text-center text-sm text-destructive"
        >
            {{ labels.error }}
        </div>

        <div v-else class="mt-6 space-y-6">
            <div
                v-if="chips.length > 0"
                role="group"
                :aria-label="overviewLabels.filter_label"
                class="flex flex-wrap gap-2"
            >
                <button
                    type="button"
                    class="inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        activeFilter === ALL
                            ? 'border-foreground bg-foreground text-background'
                            : 'border-border bg-card text-foreground hover:bg-muted'
                    "
                    :aria-pressed="activeFilter === ALL"
                    @click="filter = ALL"
                >
                    {{ overviewLabels.filter_all }}
                    <span class="font-mono text-xs tabular-nums">{{
                        all.length
                    }}</span>
                </button>
                <button
                    v-for="chip in chips"
                    :key="chip.game"
                    type="button"
                    class="inline-flex min-h-8 items-center gap-1.5 rounded-full border px-3 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    :class="
                        activeFilter === chip.game
                            ? 'border-foreground bg-foreground text-background'
                            : 'border-border bg-card text-foreground hover:bg-muted'
                    "
                    :aria-pressed="activeFilter === chip.game"
                    @click="filter = chip.game"
                >
                    {{ chip.game }}
                    <span class="font-mono text-xs tabular-nums">{{
                        chip.count
                    }}</span>
                </button>
            </div>

            <section v-if="mine.length > 0" aria-labelledby="servers-mine">
                <h2
                    id="servers-mine"
                    class="mb-2 text-sm font-medium text-muted-foreground"
                >
                    {{ overviewLabels.section_mine }}
                    <span class="font-mono tabular-nums">{{
                        mine.length
                    }}</span>
                </h2>
                <div class="divide-y rounded-md border bg-card">
                    <ServerRow
                        v-for="server in mine"
                        :key="server.id"
                        :server="server"
                        :labels="labels"
                        :permanent-labels="permanentLabels"
                        :overview-labels="overviewLabels"
                        :admin-maps="adminMaps"
                        :open="openId === server.id"
                        primary
                        show-estimate
                        @toggle="toggle(server.id)"
                    />
                </div>
            </section>

            <section
                v-if="event && (eventRows.length > 0 || mine.length === 0)"
                aria-labelledby="servers-event"
            >
                <h2
                    id="servers-event"
                    class="mb-2 text-sm font-medium text-muted-foreground"
                >
                    {{ event.name }}
                    <span class="font-mono tabular-nums">{{
                        eventRows.length
                    }}</span>
                </h2>
                <p
                    v-if="!event.isCurrent"
                    class="mb-2 text-sm text-muted-foreground"
                >
                    {{
                        overviewLabels.other_event_hint.replace(
                            ':event',
                            event.name,
                        )
                    }}
                    <Link
                        v-if="hasCurrent"
                        :href="serversIndex.url()"
                        class="text-primary underline-offset-4 hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        >{{ overviewLabels.back_to_current }}</Link
                    >
                </p>
                <div
                    v-if="eventRows.length > 0"
                    class="divide-y rounded-md border bg-card"
                >
                    <ServerRow
                        v-for="server in eventRows"
                        :key="server.id"
                        :server="server"
                        :labels="labels"
                        :permanent-labels="permanentLabels"
                        :overview-labels="overviewLabels"
                        :admin-maps="adminMaps"
                        :primary="false"
                        :open="openId === server.id"
                        show-estimate
                        @toggle="toggle(server.id)"
                    />
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    {{ emptyText('event') }}
                </p>
            </section>

            <section aria-labelledby="servers-permanent">
                <h2
                    id="servers-permanent"
                    class="mb-2 text-sm font-medium text-muted-foreground"
                >
                    {{ overviewLabels.section_permanent }}
                    <span class="font-mono tabular-nums">{{
                        permanentRows.length
                    }}</span>
                </h2>
                <div
                    v-if="permanentRows.length > 0"
                    class="divide-y rounded-md border bg-card"
                >
                    <ServerRow
                        v-for="server in permanentRows"
                        :key="`p${server.id}`"
                        :server="server"
                        :labels="labels"
                        :permanent-labels="permanentLabels"
                        :overview-labels="overviewLabels"
                        :admin-maps="adminMaps"
                        :primary="false"
                        :open="openId === server.id"
                        :show-estimate="false"
                        @toggle="toggle(server.id)"
                    />
                </div>
                <p
                    v-else
                    class="rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                >
                    {{ emptyText('permanent') }}
                </p>
            </section>
        </div>
    </PageContainer>
</template>
