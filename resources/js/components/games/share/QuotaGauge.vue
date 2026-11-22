<script setup lang="ts">
import { computed } from 'vue';
import { formatBytes } from '@/lib/bytes';
import type { GameShareQuota } from '@/types/gameShare';

/**
 * The viewer's upload quota, read as a quiet fuel gauge — the same readout as
 * the files page. It stays neutral until the viewer nears the cap, so the one
 * amber element on the page remains the submit button (docs/design.md
 * "amber is rationed").
 *
 * Near the cap the warning is carried by three things, never by the text
 * colour alone: a `bg-warn` dot, the numbers in full `text-foreground` (the
 * `text-warn` tone is ~3.3:1 on the card in light mode, below AA for small
 * text) and one sentence below the bar. The bar keeps `bg-muted-foreground`
 * for the same reason: `bg-warn` is only ~2.9:1 against the muted track,
 * below the 3:1 non-text floor, while `bg-muted-foreground` is ~5:1 in both
 * modes.
 */
const props = defineProps<{ quota: GameShareQuota }>();

const ratio = computed<number>(() => {
    if (props.quota.capBytes <= 0) {
        return 0;
    }

    return Math.min(1, props.quota.usedBytes / props.quota.capBytes);
});

const nearCap = computed<boolean>(() => ratio.value >= 0.9);
</script>

<template>
    <div class="space-y-1.5">
        <div class="flex items-baseline justify-between gap-2 text-sm">
            <span
                class="inline-flex items-center gap-1.5 text-muted-foreground"
            >
                <span
                    v-if="nearCap"
                    class="size-1.5 shrink-0 rounded-full bg-warn"
                    aria-hidden="true"
                />
                Kontingent
            </span>
            <span
                class="font-mono tabular-nums"
                :class="
                    nearCap
                        ? 'font-medium text-foreground'
                        : 'text-muted-foreground'
                "
            >
                {{ formatBytes(quota.usedBytes) }} /
                {{ formatBytes(quota.capBytes) }}
            </span>
        </div>
        <div
            class="h-1.5 w-full overflow-hidden rounded-full bg-muted"
            role="progressbar"
            aria-label="Belegter Speicherplatz"
            :aria-valuenow="Math.round(ratio * 100)"
            aria-valuemin="0"
            aria-valuemax="100"
        >
            <div
                class="h-full rounded-full bg-muted-foreground transition-[width] motion-reduce:transition-none"
                :style="{ width: `${ratio * 100}%` }"
            />
        </div>
        <p v-if="nearCap" class="text-xs text-foreground">
            Fast voll — bald passt kein weiteres Archiv mehr.
        </p>
    </div>
</template>
