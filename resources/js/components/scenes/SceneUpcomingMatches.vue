<script setup lang="ts">
import { computed } from 'vue';
import type { BracketMatchDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: { matches?: BracketMatchDto[] };
    labels: Record<string, string>;
}>();

// A fixed cap keeps every card at a beamer-legible size; the rest is a count.
const MAX_MATCHES = 8;

const shown = computed(() => (props.data.matches ?? []).slice(0, MAX_MATCHES));
const hidden = computed(() =>
    Math.max((props.data.matches ?? []).length - MAX_MATCHES, 0),
);
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.upcoming_matches_title }}
        </h1>

        <p
            v-if="!data.matches || data.matches.length === 0"
            class="text-3xl text-muted-foreground"
        >
            {{ labels.upcoming_matches_empty }}
        </p>

        <ul v-else class="grid grid-cols-2 content-start gap-6">
            <li
                v-for="match in shown"
                :key="match.id"
                class="rounded-xl border border-border bg-card px-8 py-6"
            >
                <p class="font-mono text-xl text-muted-foreground uppercase">
                    {{ labels.round.replace(':number', String(match.round)) }}
                </p>
                <p
                    class="mt-2 text-3xl font-semibold break-words text-foreground"
                >
                    {{ match.slot1 ?? labels.slot_tbd }}
                    <span class="text-muted-foreground">{{
                        labels.versus
                    }}</span>
                    {{ match.slot2 ?? labels.slot_tbd }}
                </p>
            </li>
        </ul>

        <p v-if="hidden > 0" class="text-3xl text-muted-foreground">
            {{ labels.more_count.replace(':count', String(hidden)) }}
        </p>
    </div>
</template>
