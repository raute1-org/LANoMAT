<script setup lang="ts">
import LiveIndicator from '@/components/LiveIndicator.vue';
import type { NavBadge } from '@/types/navigation';

defineProps<{ badge: NavBadge }>();

const textClass = {
    muted: 'text-muted-foreground',
    warn: 'text-warn',
    ok: 'text-ok',
} as const;
</script>

<template>
    <span
        class="ml-auto shrink-0 font-mono text-xs group-data-[collapsible=icon]:hidden"
        :class="
            badge.variant === 'live' ? '' : textClass[badge.variant ?? 'muted']
        "
        data-test="nav-badge"
    >
        <LiveIndicator v-if="badge.variant === 'live'" :label="badge.text" />
        <template v-else>{{ badge.text }}</template>
    </span>
</template>
