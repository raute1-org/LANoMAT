<script setup lang="ts">
/**
 * The participant gallery page: approved event photos plus the viewer's own
 * pending uploads. Deliberately auth-gated (see GalleryPageController's
 * docblock) — unlike the public jukebox/files boards, this page is only ever
 * reached by an authenticated user, so there is no guest state to render.
 * Structure mirrors Jukebox/Index.vue and Files/Index.vue: a page title, an
 * upload Card visible only when `canUpload`, and all four states.
 */
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import PhotoGrid from '@/components/gallery/PhotoGrid.vue';
import PhotoLightbox from '@/components/gallery/PhotoLightbox.vue';
import PhotoUpload from '@/components/gallery/PhotoUpload.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { destroy as destroyPhoto } from '@/routes/gallery';
import type { GalleryPhotoDto } from '@/types';

defineProps<{
    event: { name: string; slug: string };
    photos: GalleryPhotoDto[];
    canUpload: boolean;
    labels: Record<string, string>;
    canDownloadZip: boolean;
    photoCountLabel: string;
}>();

const openPhoto = ref<GalleryPhotoDto | null>(null);

function openLightbox(photo: GalleryPhotoDto): void {
    openPhoto.value = photo;
}

function closeLightbox(): void {
    openPhoto.value = null;
}

function remove(photo: GalleryPhotoDto): void {
    closeLightbox();
    useForm({}).delete(destroyPhoto.url({ eventPhoto: photo.id }), {
        preserveScroll: true,
    });
}

const { isNavigating, hasError } = usePageLoadState();
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="wide" :title="`${labels.title} — ${event.name}`">
        <template #actions>
            <span
                v-if="photos.length > 0"
                class="font-mono text-sm text-muted-foreground tabular-nums"
            >
                {{ photoCountLabel }}
            </span>
            <Button
                v-if="canDownloadZip"
                as-child
                size="sm"
                variant="secondary"
            >
                <a :href="`/events/${event.slug}/gallery/zip`">{{
                    labels.download_zip
                }}</a>
            </Button>
        </template>

        <div v-if="canUpload" class="mt-8">
            <PhotoUpload :event-slug="event.slug" :labels="labels" />
        </div>
        <p
            v-else
            class="mt-4 rounded-lg border border-dashed border-border p-4 text-sm text-muted-foreground"
        >
            {{ labels.no_upload_notice }}
        </p>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div
            v-if="isNavigating"
            class="mt-8 grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
        >
            <Skeleton
                v-for="n in 10"
                :key="n"
                class="aspect-square w-full rounded-md"
            />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-8 rounded-lg border border-destructive/50 bg-destructive/10 p-8 text-center"
        >
            <p class="text-sm text-destructive">{{ labels.load_error }}</p>
        </div>

        <template v-else>
            <!-- empty -->
            <div
                v-if="photos.length === 0"
                class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
            >
                <p class="text-sm text-muted-foreground">
                    {{ labels.empty }}
                </p>
            </div>

            <!-- normal -->
            <div v-else class="mt-8">
                <PhotoGrid
                    :photos="photos"
                    :labels="labels"
                    @open="openLightbox"
                />
            </div>
        </template>

        <PhotoLightbox
            :photo="openPhoto"
            :labels="labels"
            @close="closeLightbox"
            @delete="remove"
        />
    </PageContainer>
</template>
