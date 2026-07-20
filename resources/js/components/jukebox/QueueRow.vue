<script setup lang="ts">
/**
 * One vote-ordered queue entry. Position in the list is real information
 * (what plays next), so this stays a plain ordered list — no client-side
 * re-sort. Amber appears only when `hasVoted` is true (the viewer's own
 * active vote) — every other state stays graphite/outline.
 */
import { ChevronUp, X } from '@lucide/vue';
import { Button } from '@/components/ui/button';
import type { QueueItemDto } from '@/types';

defineProps<{
    item: QueueItemDto;
    canParticipate: boolean;
    canModerate: boolean;
    labels: {
        added_by: string;
        remove: string;
    };
}>();

const emit = defineEmits<{
    (e: 'vote'): void;
    (e: 'remove'): void;
}>();

function addedByLabel(template: string, name: string | null): string | null {
    return name === null ? null : template.replace(':name', name);
}
</script>

<template>
    <li
        class="flex items-center gap-3 rounded-lg border border-border bg-card p-3"
    >
        <img
            v-if="item.imageUrl"
            :src="item.imageUrl"
            :alt="item.title"
            width="40"
            height="40"
            loading="lazy"
            class="h-10 w-10 shrink-0 rounded-md object-cover"
        />
        <div
            v-else
            class="flex h-10 w-10 shrink-0 items-center justify-center rounded-md bg-muted text-sm font-semibold text-muted-foreground"
            aria-hidden="true"
        >
            ♪
        </div>

        <div class="min-w-0 flex-1">
            <p class="truncate font-medium text-foreground">
                {{ item.title }}
            </p>
            <p class="truncate text-sm text-muted-foreground">
                <span v-if="item.artist">{{ item.artist }}</span>
                <span v-if="item.artist && item.addedByName"> · </span>
                <span v-if="item.addedByName">{{
                    addedByLabel(labels.added_by, item.addedByName)
                }}</span>
            </p>
        </div>

        <Button
            type="button"
            size="sm"
            :variant="item.hasVoted ? 'default' : 'outline'"
            :disabled="!canParticipate"
            :aria-pressed="item.hasVoted"
            class="shrink-0"
            @click="emit('vote')"
        >
            <ChevronUp class="size-4" aria-hidden="true" />
            <span class="font-mono tabular-nums">{{ item.voteCount }}</span>
        </Button>

        <Button
            v-if="canModerate"
            type="button"
            size="icon"
            variant="ghost"
            class="shrink-0 text-muted-foreground hover:text-destructive"
            :aria-label="labels.remove"
            @click="emit('remove')"
        >
            <X class="size-4" aria-hidden="true" />
        </Button>
    </li>
</template>
