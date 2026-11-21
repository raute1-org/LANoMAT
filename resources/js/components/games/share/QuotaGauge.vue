<script setup lang="ts">
import { computed } from 'vue';
import { formatBytes } from '@/lib/bytes';
import type { GameShareQuota } from '@/types/gameShare';

/**
 * The viewer's upload quota, read as a quiet fuel gauge — the same readout as
 * the files page. It stays neutral until the viewer nears the cap, so the one
 * amber element on the page remains the submit button (docs/design.md
 * "amber is rationed").
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
        <div
            class="flex items-baseline justify-between gap-2 text-sm text-muted-foreground"
        >
            <span>Kontingent</span>
            <span
                class="font-mono tabular-nums"
                :class="nearCap && 'text-warn'"
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
                class="h-full rounded-full transition-[width] motion-reduce:transition-none"
                :class="nearCap ? 'bg-warn' : 'bg-muted-foreground'"
                :style="{ width: `${ratio * 100}%` }"
            />
        </div>
    </div>
</template>
