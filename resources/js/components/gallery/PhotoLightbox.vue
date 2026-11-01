<script setup lang="ts">
/**
 * Full-size photo view, opened from a PhotoGrid tile click. Fetches the
 * full-resolution image lazily (only once `photo` is set, i.e. the dialog is
 * about to open) rather than preloading every full-size photo up front.
 */
import { ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import type { GalleryPhotoDto } from '@/types';

const props = defineProps<{
    photo: GalleryPhotoDto | null;
    labels: Record<string, string>;
}>();

const emit = defineEmits<{
    close: [];
    delete: [photo: GalleryPhotoDto];
}>();

function onOpenChange(open: boolean): void {
    if (!open) {
        emit('close');
    }
}

/** Withdrawing is two steps: the first click asks, the second confirms. */
const confirming = ref(false);

watch(
    () => props.photo,
    () => {
        confirming.value = false;
    },
);

function remove(): void {
    if (props.photo) {
        emit('delete', props.photo);
    }
}
</script>

<template>
    <Dialog :open="photo !== null" @update:open="onOpenChange">
        <DialogContent v-if="photo" class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle class="sr-only">{{
                    photo.caption ?? labels.title
                }}</DialogTitle>
                <DialogDescription class="sr-only">
                    {{ labels.title }}
                </DialogDescription>
            </DialogHeader>

            <img
                :src="photo.fullUrl"
                :alt="photo.caption ?? ''"
                class="max-h-[70vh] w-full rounded-md object-contain"
            />

            <p v-if="photo.caption" class="text-sm text-foreground">
                {{ photo.caption }}
            </p>
            <p class="text-xs text-muted-foreground">
                {{ labels.uploaded_by }} {{ photo.uploaderName }}
            </p>

            <DialogFooter v-if="photo.retractable">
                <template v-if="confirming">
                    <p class="mr-auto text-sm text-muted-foreground">
                        <span class="font-medium text-foreground">{{
                            labels.delete_confirm_title
                        }}</span>
                        {{ labels.delete_confirm_body }}
                    </p>
                    <Button
                        variant="outline"
                        size="sm"
                        @click="confirming = false"
                    >
                        {{ labels.cancel }}
                    </Button>
                    <Button variant="destructive" size="sm" @click="remove">
                        {{ labels.delete }}
                    </Button>
                </template>
                <Button
                    v-else
                    variant="destructive"
                    size="sm"
                    @click="confirming = true"
                >
                    {{ labels.delete }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
