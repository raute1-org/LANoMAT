<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import type { MatchServerDto, SpectateHintDto } from '@/types';

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
 *
 * Pre-start RAM estimate (roadmap 6.7): a quiet mono readout shown only
 * while `server.estimate` is populated (i.e. not Ready yet) — the same
 * number `GuardrailPolicy` enforces server-side, not a UI-only guess. Stays
 * calm (muted-foreground) within limits; flips to `text-warn` with a short
 * label when the estimate is over the per-instance cap, since the actual
 * enforcement already happened in the job — this is informational, not a
 * blocking control.
 *
 * Spectate hint ("So schaust du zu", M10 T8): a quiet secondary block shown
 * whenever the tournament's game has one configured, independent of the
 * ServerLink's own lifecycle (it describes the game, not this particular
 * server) — mirrors the participant Servers page's install-hint block, with
 * the GOTV/observer connect string in `font-mono` (Signalpult rule: mono for
 * machine-readable data) and the two notes as plain text.
 */
const props = defineProps<{
    server: MatchServerDto | null;
    spectateHint?: SpectateHintDto | null;
    /** `gameservers.match_page` labels: connect/copy/copied/heading/... */
    labels: Record<string, string>;
    /** `gameservers.server_link_status` labels, keyed by status value. */
    statusLabels: Record<string, string>;
}>();

const copied = ref(false);
const hintCopied = ref(false);

async function copyHint(text: string) {
    await navigator.clipboard.writeText(text);
    hintCopied.value = true;
    setTimeout(() => {
        hintCopied.value = false;
    }, 2000);
}

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

        <span
            v-else-if="server.estimate"
            class="font-mono text-xs tabular-nums"
            :class="
                server.estimate.overCap ? 'text-warn' : 'text-muted-foreground'
            "
        >
            {{ labels.estimate_label }}: ~{{ server.estimate.ramMb }}
            MB
            <span v-if="server.estimate.overCap">
                ({{ labels.estimate_over_cap }})</span
            >
        </span>
    </div>

    <!-- spectate hint ("So schaust du zu"): rendered only when the
         tournament's game has one configured — nothing shown otherwise, a
         calm, unobtrusive readout rather than an empty placeholder.
         Independent of the ServerLink block above: it describes the game,
         not this particular server. Also requires `labels.spectate_hint_label`
         to be present: the bracket OBS overlay (`BracketView.vue`) omits
         `serverLabels` entirely (defaults to `{}`), since the recipe is
         aimed at participants, not casters — so the block stays hidden
         there instead of rendering with `undefined` copy. -->
    <div
        v-if="spectateHint && labels.spectate_hint_label"
        class="mt-2 space-y-1 rounded-md border border-border bg-muted/30 p-2 text-xs"
    >
        <p class="font-medium text-foreground">
            {{ labels.spectate_hint_label }}
        </p>

        <div
            v-if="spectateHint.gotvConnect"
            class="flex flex-wrap items-center gap-2"
        >
            <span class="font-mono text-muted-foreground">
                {{ spectateHint.gotvConnect }}
            </span>
            <Button
                size="icon-sm"
                variant="ghost"
                :aria-label="
                    hintCopied
                        ? labels.spectate_hint_copied
                        : labels.spectate_hint_copy
                "
                @click="copyHint(spectateHint.gotvConnect)"
            >
                <Check v-if="hintCopied" class="size-3.5 text-ok" />
                <Copy v-else class="size-3.5" />
            </Button>
        </div>

        <p v-if="spectateHint.observerNote" class="text-muted-foreground">
            {{ spectateHint.observerNote }}
        </p>

        <p v-if="spectateHint.replayNote" class="text-muted-foreground">
            {{ spectateHint.replayNote }}
        </p>
    </div>
</template>
