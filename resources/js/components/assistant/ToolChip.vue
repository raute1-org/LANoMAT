<script setup lang="ts">
import { Check, CircleAlert, CircleSlash, Clock, Wrench } from '@lucide/vue';
import { computed } from 'vue';
import { toolNameOf, toolStateLabel } from '@/lib/assistantMessages';
import type { ToolPart } from '@/types/assistant';

const props = defineProps<{ part: ToolPart }>();

const name = computed(() => toolNameOf(props.part));
const label = computed(() => toolStateLabel(props.part.state));
const tone = computed(() => {
    switch (props.part.state) {
        case 'output-available':
            return 'text-muted-foreground';
        case 'output-error':
            return 'text-destructive';
        case 'output-denied':
            return 'text-muted-foreground';
        case 'approval-requested':
            return 'text-foreground';
        default:
            return 'text-muted-foreground';
    }
});
</script>

<template>
    <span
        class="inline-flex max-w-full items-center gap-1.5 rounded-md border border-border bg-muted/40 px-2 py-1 text-xs"
        :class="tone"
    >
        <Check
            v-if="part.state === 'output-available'"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <CircleAlert
            v-else-if="part.state === 'output-error'"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <CircleSlash
            v-else-if="part.state === 'output-denied'"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <Clock
            v-else-if="part.state === 'approval-requested'"
            class="size-3.5 shrink-0"
            aria-hidden="true"
        />
        <Wrench
            v-else
            class="size-3.5 shrink-0 motion-safe:animate-pulse"
            aria-hidden="true"
        />
        <span class="truncate font-mono">{{ name }}</span>
        <span class="shrink-0">{{ label }}</span>
    </span>
</template>
