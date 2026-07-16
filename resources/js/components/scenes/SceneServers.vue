<script setup lang="ts">
import type { GameServerDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: { servers?: GameServerDto[] };
    labels: Record<string, string>;
}>();

const dotColorClass: Record<'ok' | 'warn' | 'down', string> = {
    ok: 'bg-ok',
    warn: 'bg-warn',
    down: 'bg-down',
};

const liveVariant: Record<GameServerDto['status'], 'ok' | 'warn' | 'down'> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    failed: 'down',
    stopped: 'down',
};

function servers(): GameServerDto[] {
    return props.data.servers ?? [];
}

function statusLabel(status: GameServerDto['status']): string {
    return props.labels[`status_${status}`] ?? status;
}
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.servers_title }}
        </h1>

        <p v-if="servers().length === 0" class="text-3xl text-muted-foreground">
            {{ labels.servers_empty }}
        </p>

        <ul v-else class="flex-1 space-y-4 overflow-auto">
            <li
                v-for="server in servers()"
                :key="server.id"
                class="flex items-center justify-between gap-8 rounded-xl bg-card px-8 py-6"
            >
                <div>
                    <p class="text-3xl font-semibold text-foreground">
                        {{ server.game ?? labels.servers_title }}
                    </p>
                    <p
                        v-if="server.matchLabel"
                        class="mt-1 text-xl text-muted-foreground"
                    >
                        {{ server.matchLabel }}
                    </p>
                </div>

                <div
                    class="flex items-center gap-8 font-mono text-2xl text-foreground tabular-nums"
                >
                    <span v-if="server.address">
                        <span class="text-lg text-muted-foreground uppercase">
                            {{ labels.servers_address_label }}
                        </span>
                        <br />
                        {{ server.address }}
                    </span>
                    <span v-if="server.port">
                        <span class="text-lg text-muted-foreground uppercase">
                            {{ labels.servers_port_label }}
                        </span>
                        <br />
                        {{ server.port }}
                    </span>
                    <span class="flex items-center gap-3">
                        <span class="relative inline-flex size-4">
                            <span
                                v-if="server.status === 'provisioning'"
                                class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 motion-reduce:animate-none"
                                :class="
                                    dotColorClass[liveVariant[server.status]]
                                "
                            />
                            <span
                                class="relative inline-flex size-4 rounded-full"
                                :class="
                                    dotColorClass[liveVariant[server.status]]
                                "
                            />
                        </span>
                        <span class="text-lg uppercase">{{
                            statusLabel(server.status)
                        }}</span>
                    </span>
                </div>
            </li>
        </ul>
    </div>
</template>
