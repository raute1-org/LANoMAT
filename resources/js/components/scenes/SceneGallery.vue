<script setup lang="ts">
// The beamer photo slideshow (Task 7): a full-bleed, timed crossfade
// rotation through the event's approved gallery photos (GalleryQuery's
// read-model, no PII — see ScenePayload::galleryData()). One focal thing on
// screen at a time, same "loud beamer" register as SceneNowPlaying/
// ScenePresence, mirroring their `config`/`data`/`labels` prop contract.
//
// Respects `prefers-reduced-motion` via Tailwind's `motion-safe:`/
// `motion-reduce:` variants (mirrors SceneGong/LiveIndicator): with reduced
// motion the crossfade transition classes never apply, so Vue's <Transition>
// swaps the photo with a plain cut instead of animating opacity.
//
// A newly-approved photo appears automatically: GalleryUpdated broadcasts
// `.gallery.updated` on the public `event.{id}` channel, and Screen/Show.vue
// reloads the scene payloads on that event (same mechanism as
// `.jukebox.updated`), which re-fetches this component's `data.photos`.
import { computed, onUnmounted, ref, watch } from 'vue';
import type { GallerySlidePhotoDto } from '@/types';

const props = defineProps<{
    config: { headline?: string };
    data: { photos?: GallerySlidePhotoDto[] };
    labels: Record<string, string>;
}>();

const ROTATE_MS = 8000;

const photos = computed<GallerySlidePhotoDto[]>(() => props.data.photos ?? []);
const index = ref(0);
let timer: ReturnType<typeof setInterval> | undefined;

function startRotation(): void {
    stopRotation();

    if (photos.value.length < 2) {
        return;
    }

    timer = setInterval(() => {
        index.value = (index.value + 1) % photos.value.length;
    }, ROTATE_MS);
}

function stopRotation(): void {
    if (timer !== undefined) {
        clearInterval(timer);
        timer = undefined;
    }
}

watch(
    photos,
    () => {
        index.value = 0;
        startRotation();
    },
    { immediate: true },
);

onUnmounted(stopRotation);

const current = computed<GallerySlidePhotoDto | undefined>(
    () => photos.value[index.value],
);
</script>

<template>
    <div class="relative h-full w-full">
        <p
            v-if="photos.length === 0"
            class="flex h-full w-full items-center justify-center px-12 text-center text-4xl text-muted-foreground"
        >
            {{ labels.gallery_empty }}
        </p>

        <Transition
            v-else
            mode="out-in"
            enter-active-class="motion-safe:animate-in motion-safe:fade-in motion-safe:duration-1000"
            leave-active-class="motion-safe:animate-out motion-safe:fade-out motion-safe:duration-1000"
        >
            <div v-if="current" :key="current.url" class="absolute inset-0">
                <img
                    :src="current.url"
                    alt=""
                    loading="lazy"
                    width="1920"
                    height="1080"
                    class="h-full w-full object-contain"
                />

                <p
                    v-if="current.caption"
                    class="absolute right-0 bottom-0 left-0 truncate bg-background/70 px-12 py-6 text-3xl text-foreground"
                >
                    {{ current.caption }}
                </p>
            </div>
        </Transition>

        <h1
            v-if="config.headline"
            class="absolute top-0 right-0 left-0 truncate bg-background/70 px-12 py-6 text-4xl font-bold tracking-tight text-foreground"
        >
            {{ config.headline }}
        </h1>
    </div>
</template>
