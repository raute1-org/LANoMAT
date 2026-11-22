<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ResumableUploader from '@/components/files/ResumableUploader.vue';
import { Button, buttonVariants } from '@/components/ui/button';
import { formatBytes } from '@/lib/bytes';
import type { GameShareUpload } from '@/types/gameShare';

/**
 * The upload control of the share page. It carries the same switch as the
 * files page: chunked uploads straight to the file host when a ticket exists,
 * otherwise the plain multipart form. Either way the file only lands in the
 * viewer's file list (as `pending`) — the share itself is a separate step on
 * this page, and the page selects the archive it just received. The pick
 * button is outline here: "Spiel teilen" stays the page's one primary (amber)
 * action.
 */
const props = defineProps<{
    upload: GameShareUpload;
    uploadLabels: Record<string, string>;
    archiveExtensions: string[];
}>();

const emit = defineEmits<{
    /** A form upload starts: the page snapshots the list to compare against. */
    started: [];
    /** The upload finished; the picked name when the mode reports it. */
    finished: [uploadedName: string | null];
}>();

const form = useForm<{ file: File | null }>({ file: null });

const accept = computed<string>(() => props.archiveExtensions.join(','));

const selectedName = ref<string | null>(null);

// About the next upload, not about the list below: an archive can be selected
// there while nothing is picked here.
const pickerStatus = computed<string>(
    () => selectedName.value ?? 'Noch keine Datei zum Hochladen gewählt',
);

const uploadHint = computed<string>(
    () =>
        `Erlaubt sind ${props.archiveExtensions.join(' und ')}, bis ${formatBytes(props.upload.maxFileBytes)} pro Datei. Nach dem Hochladen ist die Datei unten ausgewählt.`,
);

/** The hint is always described; the error only while the server rejects it. */
const describedBy = computed<string>(() =>
    form.errors.file
        ? 'share-upload-file-hint share-upload-file-error'
        : 'share-upload-file-hint',
);

function onFileChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.file = target.files?.[0] ?? null;
    selectedName.value = form.file?.name ?? null;
}

function submit(): void {
    if (form.file === null) {
        return;
    }

    const name = form.file.name;

    // The POST redirects back to this page (`back()`), so the list the page
    // receives already holds the new file; the baseline has to be taken now.
    emit('started');

    form.post(props.upload.storeUrl, {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            selectedName.value = null;
            emit('finished', name);
        },
    });
}

function resumableFinished(): void {
    // The resumable uploader reports completion, not the file it finished, so
    // the page picks the newest archive that appeared instead of guessing.
    emit('finished', null);
}
</script>

<template>
    <ResumableUploader
        v-if="upload.mode === 'resumable' && upload.ticketUrl"
        :ticket-url="upload.ticketUrl"
        :max-file-bytes="upload.maxFileBytes"
        :labels="uploadLabels"
        :on-finished="resumableFinished"
        pick-variant="outline"
    />

    <form v-else class="space-y-3" @submit.prevent="submit">
        <div class="flex flex-wrap items-center gap-3">
            <input
                id="share-upload-file"
                type="file"
                class="peer sr-only"
                :accept="accept"
                required
                :aria-describedby="describedBy"
                @change="onFileChange"
            />
            <label
                for="share-upload-file"
                :class="[
                    buttonVariants({ variant: 'outline' }),
                    'cursor-pointer peer-focus-visible:border-ring peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50',
                ]"
            >
                {{ uploadLabels.upload_label }}
            </label>
            <span
                class="min-w-0 truncate text-sm text-muted-foreground"
                aria-live="polite"
                >{{ pickerStatus }}</span
            >
        </div>

        <p
            id="share-upload-file-hint"
            class="max-w-prose text-xs text-muted-foreground"
        >
            {{ uploadHint }}
        </p>
        <p
            v-if="form.errors.file"
            id="share-upload-file-error"
            role="alert"
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
    </form>
</template>
