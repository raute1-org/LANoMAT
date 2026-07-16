<script setup lang="ts">
import type { BracketMatchDto } from '@/types';

defineProps<{
    config: { headline?: string };
    data: { matches?: BracketMatchDto[] };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="flex h-full w-full flex-col gap-8 px-16 py-12">
        <h1 class="text-5xl font-bold tracking-tight">
            {{ config.headline ?? labels.upcoming_matches_title }}
        </h1>

        <p
            v-if="!data.matches || data.matches.length === 0"
            class="text-3xl text-white/80"
        >
            {{ labels.upcoming_matches_empty }}
        </p>

        <ul v-else class="grid flex-1 grid-cols-2 content-start gap-6">
            <li
                v-for="match in data.matches"
                :key="match.id"
                class="rounded-xl border border-white/20 bg-white/10 px-8 py-6"
            >
                <p class="text-xl text-white/70">
                    {{ labels.round.replace(':number', String(match.round)) }}
                </p>
                <p class="mt-2 text-3xl font-semibold">
                    {{ match.slot1 ?? labels.slot_tbd }}
                    <span class="text-white/50">{{ labels.versus }}</span>
                    {{ match.slot2 ?? labels.slot_tbd }}
                </p>
            </li>
        </ul>
    </div>
</template>
