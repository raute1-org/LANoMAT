<script setup lang="ts">
/**
 * The Signalpult signature: a small signal dot + optional mono label marking
 * "happening now" state (a live match, an open check-in window, service
 * health, ...). See docs/design.md § "The signature: live-state treatment".
 *
 * Usage: <LiveIndicator label="LIVE" /> · <LiveIndicator variant="down" label="Offline" />
 */
withDefaults(
    defineProps<{
        variant?: 'live' | 'ok' | 'warn' | 'down';
        label?: string;
        pulse?: boolean;
    }>(),
    {
        variant: 'live',
        label: undefined,
        pulse: true,
    },
);

const dotColorClass: Record<'live' | 'ok' | 'warn' | 'down', string> = {
    live: 'bg-live',
    ok: 'bg-ok',
    warn: 'bg-warn',
    down: 'bg-down',
};
</script>

<template>
    <span class="inline-flex items-center gap-1.5">
        <span class="relative inline-flex size-2">
            <span
                v-if="pulse"
                class="absolute inline-flex h-full w-full animate-ping rounded-full opacity-75 motion-reduce:animate-none"
                :class="dotColorClass[variant]"
            />
            <span
                class="relative inline-flex size-2 rounded-full"
                :class="dotColorClass[variant]"
            />
        </span>
        <span
            v-if="label"
            class="font-mono text-xs tracking-wide text-muted-foreground uppercase tabular-nums"
        >
            {{ label }}
        </span>
    </span>
</template>
