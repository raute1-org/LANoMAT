<script setup lang="ts">
/**
 * The gallery's photo tile grid. Every tile is a fixed-aspect square
 * (`aspect-square`) so the grid never shifts as thumbnails stream in —
 * combined with `loading="lazy"` and explicit width/height attributes per
 * the design system's quality floor (docs/design.md). Amber is reserved for
 * the upload CTA elsewhere on the page; here the only accent colour is the
 * `--warn` pending badge, matching Files/Index.vue's "wird geprüft" pattern.
 */
import { Badge } from '@/components/ui/badge';
import type { GalleryPhotoDto } from '@/types';

defineProps<{
    photos: GalleryPhotoDto[];
    labels: Record<string, string>;
}>();

const emit = defineEmits<{
    open: [photo: GalleryPhotoDto];
}>();
</script>

<template>
    <ul
        class="grid grid-cols-2 gap-3 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5"
    >
        <li v-for="photo in photos" :key="photo.id" class="relative">
            <button
                type="button"
                class="group block aspect-square w-full overflow-hidden rounded-md border border-border bg-muted focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="emit('open', photo)"
            >
                <img
                    :src="photo.thumbUrl"
                    :alt="photo.caption ?? ''"
                    width="400"
                    height="400"
                    loading="lazy"
                    class="h-full w-full object-cover transition-transform duration-200 group-hover:scale-105 motion-reduce:transition-none motion-reduce:group-hover:scale-100"
                />
            </button>

            <div
                v-if="
                    photo.isHighlight ||
                    (photo.mine && photo.visibility === 'pending')
                "
                class="pointer-events-none absolute top-1.5 left-1.5 flex gap-1"
            >
                <Badge v-if="photo.isHighlight" class="pointer-events-auto">
                    {{ labels.highlight_badge }}
                </Badge>
                <Badge
                    v-if="photo.mine && photo.visibility === 'pending'"
                    variant="outline"
                    class="pointer-events-auto bg-card text-warn"
                >
                    {{ labels.pending_badge }}
                </Badge>
            </div>
        </li>
    </ul>
</template>
