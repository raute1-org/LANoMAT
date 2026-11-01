<script setup lang="ts">
import { computed } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import type { WebVoiceChannelDto } from '@/types';

const props = defineProps<{
    channels: WebVoiceChannelDto[];
    selectedId: number | null;
    joinedId: number | null;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{ select: [id: number] }>();

const general = computed(() =>
    props.channels.filter((channel) => channel.kind === 'general'),
);
const matches = computed(() =>
    props.channels.filter((channel) => channel.kind !== 'general'),
);

const groups = computed(() => [
    { key: 'general', title: props.labels.general, items: general.value },
    { key: 'match', title: props.labels.matches, items: matches.value },
]);
</script>

<template>
    <nav :aria-label="labels.channels" class="space-y-5">
        <template v-for="group in groups" :key="group.key">
            <section v-if="group.items.length > 0">
                <h2 class="mb-2 px-2 text-sm font-medium text-muted-foreground">
                    {{ group.title }}
                </h2>
                <ul class="space-y-1">
                    <li v-for="channel in group.items" :key="channel.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-3 rounded-md border px-3 py-2 text-left text-sm transition-colors outline-none focus-visible:ring-2 focus-visible:ring-ring pointer-coarse:min-h-11"
                            :class="
                                selectedId === channel.id
                                    ? 'border-border bg-accent text-accent-foreground'
                                    : 'border-transparent hover:bg-accent/50'
                            "
                            :aria-current="
                                selectedId === channel.id ? 'true' : undefined
                            "
                            @click="emit('select', channel.id)"
                        >
                            <span class="min-w-0">
                                <span class="block truncate font-medium">{{
                                    channel.name
                                }}</span>
                                <span
                                    v-if="channel.description"
                                    class="block truncate text-xs text-muted-foreground"
                                >
                                    {{ channel.description }}
                                </span>
                            </span>
                            <LiveIndicator
                                v-if="joinedId === channel.id"
                                :aria-label="labels.live"
                            />
                        </button>
                    </li>
                </ul>
            </section>
        </template>
    </nav>
</template>
