<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';
import { permanent as serversPermanent } from '@/routes/servers';
import type { PermanentShortDto } from '@/types/now';
import JoinButton from './JoinButton.vue';
import Panel from './Panel.vue';
import { useNowLabels } from './useNowLabels';

defineProps<{ title: string; servers: PermanentShortDto[] }>();

const t = useNowLabels();

function online(server: PermanentShortDto): boolean {
    return server.status === 'ready' || server.status === 'running';
}
</script>

<template>
    <Panel
        :title="title"
        :link="{ href: serversPermanent.url(), label: t('all_servers') }"
    >
        <p
            v-if="servers.length === 0"
            class="border-t border-border px-4 py-4 text-sm text-muted-foreground"
        >
            {{ t('permanent_empty') }}
        </p>
        <ul v-else class="divide-y divide-border border-t border-border">
            <li
                v-for="server in servers"
                :key="server.id"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 px-4 py-2.5 text-sm"
            >
                <LiveIndicator
                    :variant="online(server) ? 'ok' : 'down'"
                    :pulse="false"
                />
                <span class="min-w-0 flex-1 basis-40">
                    <span class="font-medium text-foreground">{{
                        server.title
                    }}</span>
                    <span v-if="server.game" class="text-muted-foreground">
                        · {{ server.game }}</span
                    >
                </span>
                <template v-if="online(server)">
                    <span
                        v-if="server.maxPlayers"
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ server.players ?? 0 }}/{{ server.maxPlayers }}
                    </span>
                    <JoinButton
                        :connect-string="server.connectString"
                        :connect-is-link="server.connectIsLink"
                    />
                </template>
                <span v-else class="text-xs text-muted-foreground">{{
                    t('offline')
                }}</span>
            </li>
        </ul>
    </Panel>
</template>
