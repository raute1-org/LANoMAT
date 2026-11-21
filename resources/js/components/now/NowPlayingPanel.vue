<script setup lang="ts">
import Panel from '@/components/Panel.vue';
import { Skeleton } from '@/components/ui/skeleton';
import { index as serversIndex } from '@/routes/servers';
import type { NowPlayingRow } from '@/types/now';
import JoinButton from './JoinButton.vue';
import { useNowLabels } from './useNowLabels';

defineProps<{
    rows?: NowPlayingRow[] | null;
    loading?: boolean;
    /** The deferred prop failed to load. */
    failed?: boolean;
}>();

const t = useNowLabels();
const SEGMENTS = 12;

function where(row: NowPlayingRow): string {
    if (row.servers === 0) {
        return t('no_own_server');
    }

    const base = t('servers_count', { count: row.servers });

    return row.maxPlayers > 0
        ? `${base} · ${t('slots', { players: row.players, max: row.maxPlayers })}`
        : base;
}
</script>

<template>
    <Panel
        :title="t('now_playing')"
        :link="{ href: serversIndex.url(), label: t('all_servers') }"
    >
        <ul
            v-if="loading"
            class="space-y-3 border-t border-border px-4 py-3"
            :aria-label="t('loading')"
            aria-busy="true"
        >
            <li v-for="n in 3" :key="n" class="flex items-center gap-3">
                <Skeleton class="h-4 w-32" />
                <Skeleton class="h-4 w-24" />
            </li>
        </ul>
        <p
            v-else-if="failed"
            class="border-t border-border px-4 py-3 text-sm text-destructive"
            role="alert"
        >
            {{ t('load_failed') }}
        </p>
        <p
            v-else-if="!rows || rows.length === 0"
            class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
        >
            {{ t('now_playing_empty') }}
        </p>
        <ul v-else class="divide-y divide-border border-t border-border">
            <li
                v-for="row in rows"
                :key="row.game"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5"
            >
                <span
                    class="min-w-0 flex-1 basis-40 text-sm font-medium text-foreground"
                >
                    {{ row.game }}
                </span>
                <span
                    class="inline-flex items-center gap-[3px]"
                    role="img"
                    :aria-label="
                        t('people_playing', {
                            count: row.people,
                            game: row.game,
                        })
                    "
                >
                    <span
                        v-for="n in SEGMENTS"
                        :key="n"
                        class="inline-block h-4 w-[5px] rounded-[1px]"
                        :class="
                            n <= row.people ? 'bg-foreground/80' : 'bg-border'
                        "
                    />
                </span>
                <span
                    class="font-mono text-xs text-foreground tabular-nums"
                    aria-hidden="true"
                >
                    {{ row.people }}
                </span>
                <span
                    class="min-w-0 flex-1 basis-32 text-xs text-muted-foreground"
                >
                    {{ where(row) }}
                </span>
                <JoinButton
                    v-if="row.join"
                    :connect-string="row.join.connectString"
                    :connect-is-link="row.join.connectIsLink"
                />
            </li>
        </ul>
        <p
            v-if="!loading && rows && rows.length > 0"
            class="border-t border-border px-4 py-2 text-xs text-muted-foreground"
        >
            {{ t('now_playing_note') }}
        </p>
    </Panel>
</template>
