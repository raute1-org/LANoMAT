<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import type { MatchServerDto } from '@/types';

/**
 * The match card's game-server join block (Task 5): a `LiveIndicator`
 * mirroring the ServerLink lifecycle (Provisioning -> warn+pulse, Failed ->
 * down, Ready -> ok), the `address:port` rendered in `font-mono` (Signalpult
 * rule: mono for machine-readable data), and a "Verbinden" action once the
 * server is Ready — a real `steam://`/direct-connect link when available,
 * otherwise a copy-to-clipboard fallback for `address:port`.
 *
 * Hidden entirely while `server` is null (no ServerLink provisioned at
 * all — most matches, most of the time) to keep the calm/quiet card from
 * growing a permanent empty slot.
 */
const props = defineProps<{
    server: MatchServerDto | null;
    /** `gameservers.match_page` labels: connect/copy/copied/heading/... */
    labels: Record<string, string>;
    /** `gameservers.server_link_status` labels, keyed by status value. */
    statusLabels: Record<string, string>;
}>();

const copied = ref(false);

const variant = computed<'ok' | 'warn' | 'down' | null>(() => {
    switch (props.server?.status) {
        case 'ready':
            return 'ok';
        case 'provisioning':
        case 'pending':
            return 'warn';
        case 'failed':
            return 'down';
        default:
            return null;
    }
});

const addressPort = computed(() => {
    if (!props.server?.address) {
        return null;
    }

    return props.server.port !== null
        ? `${props.server.address}:${props.server.port}`
        : props.server.address;
});

const isDirectLink = computed(() =>
    (props.server?.connectString ?? '').includes('://'),
);

async function copyAddress() {
    if (!addressPort.value) {
        return;
    }

    await navigator.clipboard.writeText(addressPort.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <div
        v-if="server"
        class="mt-2 flex flex-wrap items-center gap-2 border-t border-border pt-2"
    >
        <LiveIndicator
            v-if="variant"
            :variant="variant"
            :label="statusLabels[server.status]"
            :pulse="variant === 'warn'"
        />

        <template v-if="server.status === 'ready' && addressPort">
            <span class="font-mono text-xs text-muted-foreground tabular-nums">
                {{ addressPort }}
            </span>

            <Button
                v-if="isDirectLink"
                as="a"
                :href="server.connectString ?? undefined"
                size="sm"
                variant="outline"
            >
                {{ labels.connect }}
            </Button>

            <Button
                size="icon-sm"
                variant="ghost"
                :aria-label="copied ? labels.copied : labels.copy"
                @click="copyAddress"
            >
                <Check v-if="copied" class="size-3.5 text-ok" />
                <Copy v-else class="size-3.5" />
            </Button>
        </template>
    </div>
</template>
