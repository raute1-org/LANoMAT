<script setup lang="ts">
import { computed } from 'vue';
import type { GameServerQueryDto } from '@/types';

const props = defineProps<{
    query: GameServerQueryDto | null;
    labels: Record<string, string>;
    /** Render the list expanded (the server rows open it by default). */
    defaultOpen?: boolean;
}>();

// Shown only when the server sent the list (logged-in viewers, a server that
// answered its last query); a switched-off or silent server has no `query`.
const players = computed(() => props.query?.playerList ?? null);

function playTime(seconds: number | null): string {
    if (seconds === null) {
        return '';
    }

    const minutes = Math.floor(seconds / 60);

    return minutes >= 60
        ? `${Math.floor(minutes / 60)}:${String(minutes % 60).padStart(2, '0')} h`
        : `${minutes} min`;
}
</script>

<template>
    <details
        v-if="players !== null"
        :open="defaultOpen"
        class="group rounded-md border border-border text-sm"
    >
        <summary
            class="flex min-h-10 cursor-pointer list-none items-center justify-between gap-2 rounded-md px-3 py-2 focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
        >
            <span class="font-medium">{{ labels.who_online }}</span>
            <span class="flex items-center gap-2">
                <span class="font-mono text-muted-foreground tabular-nums">
                    {{ query?.players ?? 0 }}
                </span>
                <span
                    aria-hidden="true"
                    class="text-muted-foreground transition-transform group-open:rotate-180 motion-reduce:transition-none"
                    >▾</span
                >
            </span>
        </summary>

        <div class="space-y-2 border-t border-border px-3 py-2">
            <p
                v-if="players.length === 0 && (query?.players ?? 0) === 0"
                class="text-muted-foreground"
            >
                {{ labels.who_empty }}
            </p>
            <p v-else-if="players.length === 0" class="text-muted-foreground">
                {{ labels.who_unknown }}
            </p>
            <ul v-else class="divide-y divide-border">
                <li
                    v-for="(player, index) in players"
                    :key="`${index}-${player.name}`"
                    class="flex items-baseline justify-between gap-3 py-1"
                >
                    <span class="min-w-0 break-words">{{ player.name }}</span>
                    <span
                        class="shrink-0 font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        <span v-if="player.score !== null"
                            ><span class="sr-only">{{ labels.who_score }}</span
                            >{{ player.score }}</span
                        >
                        <span v-if="player.seconds !== null">
                            ·
                            <span class="sr-only">{{ labels.who_time }}</span
                            >{{ playTime(player.seconds) }}</span
                        >
                    </span>
                </li>
            </ul>
            <p class="text-xs text-muted-foreground">{{ labels.who_hint }}</p>
        </div>
    </details>
</template>
