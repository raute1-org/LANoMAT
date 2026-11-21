<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';
import FilePicker from '@/components/FilePicker.vue';
import ResumableUploader from '@/components/files/ResumableUploader.vue';
import { Button } from '@/components/ui/button';
import type { GameShareUpload } from '@/types/gameShare';

/**
 * The upload control of the share page. It carries the same switch as the
 * files page: chunked uploads straight to the file host when a ticket exists,
 * otherwise the plain multipart form. Either way the file only lands in the
 * viewer's file list (as `pending`) — the share itself is a separate step on
 * this page.
 */
const props = defineProps<{
    upload: GameShareUpload;
    uploadLabels: Record<string, string>;
    archiveExtensions: string[];
}>();

const emit = defineEmits<{ finished: [] }>();

const form = useForm<{ file: File | null }>({ file: null });

const accept = computed<string>(() => props.archiveExtensions.join(','));

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
}

function submit(): void {
    if (form.file === null) {
        return;
    }

    form.post(props.upload.storeUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            emit('finished');
        },
    });
}

function resumableFinished(): void {
    emit('finished');
}
</script>

<template>
    <ResumableUploader
        v-if="upload.mode === 'resumable' && upload.ticketUrl"
        :ticket-url="upload.ticketUrl"
        :max-file-bytes="upload.maxFileBytes"
        :labels="uploadLabels"
        :on-finished="resumableFinished"
    />

    <form v-else class="space-y-3" @submit.prevent="submit">
        <FilePicker
            id="share-upload-file"
            :accept="accept"
            required
            @change="onFileChange"
        />
        <p
            v-if="form.errors.file"
            id="share-upload-file-error"
            class="text-sm text-destructive"
        >
            {{ form.errors.file }}
        </p>
        <Button
            type="submit"
            variant="outline"
            class="h-11 sm:h-9"
            :disabled="form.processing || form.file === null"
        >
            {{
                form.processing
                    ? uploadLabels.uploading
                    : uploadLabels.upload_submit
            }}
        </Button>
        <p class="max-w-prose text-xs text-muted-foreground">
            Nach dem Hochladen erscheint die Datei unten in der Liste — wähle
            sie dort aus. Sie wartet auf die Freigabe durch die Orga.
        </p>
    </form>
</template>
