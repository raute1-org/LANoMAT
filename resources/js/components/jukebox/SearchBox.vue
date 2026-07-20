<script setup lang="ts">
/**
 * Search-to-add: queries `jukebox.search` (a plain JSON GET, not an Inertia
 * visit — the result list is transient UI state, never a page prop) and
 * renders each hit with an "Hinzufügen" affordance. Debounced so every
 * keystroke doesn't round-trip to Music Assistant.
 */
import { onUnmounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { search as searchRoute } from '@/routes/jukebox';
import type { TrackSearchResultDto } from '@/types';

const props = defineProps<{
    eventSlug: string;
    labels: {
        search_placeholder: string;
        search_error: string;
        search_no_results: string;
        search_hint: string;
        add: string;
    };
}>();

const emit = defineEmits<{
    (e: 'add', track: TrackSearchResultDto): void;
}>();

const query = ref('');
const results = ref<TrackSearchResultDto[]>([]);
const isLoading = ref(false);
const errorMessage = ref<string | null>(null);
const hasSearched = ref(false);

let debounceHandle: ReturnType<typeof setTimeout> | undefined;

function formatDuration(seconds: number | null): string {
    if (seconds === null) {
        return '--:--';
    }

    const clamped = Math.max(0, Math.floor(seconds));

    return `${Math.floor(clamped / 60)}:${(clamped % 60).toString().padStart(2, '0')}`;
}

async function runSearch(q: string): Promise<void> {
    if (q.trim().length === 0) {
        results.value = [];
        errorMessage.value = null;
        hasSearched.value = false;

        return;
    }

    isLoading.value = true;
    errorMessage.value = null;

    try {
        const response = await fetch(
            `${searchRoute.url(props.eventSlug)}?q=${encodeURIComponent(q)}`,
            {
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            },
        );

        if (!response.ok) {
            errorMessage.value = props.labels.search_error;
            results.value = [];

            return;
        }

        const data: TrackSearchResultDto[] | { results: []; error: string } =
            await response.json();

        if (Array.isArray(data)) {
            results.value = data;
        } else {
            results.value = [];
            errorMessage.value = data.error;
        }
    } catch {
        errorMessage.value = props.labels.search_error;
        results.value = [];
    } finally {
        isLoading.value = false;
        hasSearched.value = true;
    }
}

function onInput(): void {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }

    debounceHandle = setTimeout(() => {
        void runSearch(query.value);
    }, 300);
}

onUnmounted(() => {
    if (debounceHandle) {
        clearTimeout(debounceHandle);
    }
});
</script>

<template>
    <div class="space-y-3">
        <Input
            v-model="query"
            type="search"
            :placeholder="labels.search_placeholder"
            @input="onInput"
        />

        <!-- loading -->
        <div v-if="isLoading" class="space-y-2">
            <Skeleton class="h-14 w-full rounded-lg" />
            <Skeleton class="h-14 w-full rounded-lg" />
        </div>

        <!-- error (Music Assistant unreachable) -->
        <p
            v-else-if="errorMessage"
            class="rounded-lg border border-destructive/50 bg-destructive/10 p-3 text-sm text-destructive"
        >
            {{ errorMessage }}
        </p>

        <!-- normal: results -->
        <ul v-else-if="results.length > 0" class="space-y-2">
            <li
                v-for="track in results"
                :key="track.uri"
                class="flex items-center gap-3 rounded-lg border border-border p-3"
            >
                <img
                    v-if="track.imageUrl"
                    :src="track.imageUrl"
                    :alt="track.title"
                    width="36"
                    height="36"
                    loading="lazy"
                    class="h-9 w-9 shrink-0 rounded-md object-cover"
                />
                <div class="min-w-0 flex-1">
                    <p class="truncate text-sm font-medium text-foreground">
                        {{ track.title }}
                    </p>
                    <p class="truncate text-xs text-muted-foreground">
                        <span v-if="track.artist">{{ track.artist }}</span>
                        <span
                            v-if="
                                track.artist && track.durationSeconds !== null
                            "
                        >
                            ·
                        </span>
                        <span
                            v-if="track.durationSeconds !== null"
                            class="font-mono tabular-nums"
                            >{{ formatDuration(track.durationSeconds) }}</span
                        >
                    </p>
                </div>
                <Button type="button" size="sm" @click="emit('add', track)">
                    {{ labels.add }}
                </Button>
            </li>
        </ul>

        <!-- empty: searched, no hits -->
        <p
            v-else-if="hasSearched"
            class="rounded-lg border border-dashed border-border p-4 text-center text-sm text-muted-foreground"
        >
            {{ labels.search_no_results }}
        </p>

        <!-- empty: not yet searched -->
        <p v-else class="text-sm text-muted-foreground">
            {{ labels.search_hint }}
        </p>
    </div>
</template>
