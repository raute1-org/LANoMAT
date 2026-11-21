<script setup lang="ts">
import { formatBytes } from '@/lib/bytes';
import type { GameShareFile } from '@/types/gameShare';

/**
 * The viewer's own files of the current event as a radio group — newest first,
 * exactly the order the server sends. A file the server marked `usable: false`
 * (its name is not an allowed archive extension) stays visible but disabled,
 * with the reason, so a mistyped upload is explainable instead of missing.
 */
defineProps<{
    files: GameShareFile[];
    selected: number | null;
    archiveExtensions: string[];
    canUpload: boolean;
    error?: string;
}>();

const emit = defineEmits<{ 'update:selected': [id: number] }>();

function visibilityLabel(file: GameShareFile): string {
    return file.visibility === 'pending'
        ? 'Wartet auf Freigabe'
        : 'Freigegeben';
}
</script>

<template>
    <fieldset
        id="share-file-group"
        class="min-w-0 rounded-sm outline-none focus-visible:ring-2 focus-visible:ring-ring"
        tabindex="-1"
        :aria-describedby="error ? 'share-file-error' : undefined"
    >
        <legend class="sr-only">Datei auswählen</legend>

        <!-- empty: the invitation lives in the upload section above -->
        <p
            v-if="files.length === 0"
            class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
        >
            <template v-if="canUpload">
                Noch kein Archiv hochgeladen — lade oben eins hoch, es erscheint
                dann hier.
            </template>
            <template v-else>
                Noch kein Archiv auf dem Dateiserver. Sobald eins da ist, kannst
                du es hier auswählen.
            </template>
        </p>

        <div v-else class="divide-y divide-border border-t border-border">
            <label
                v-for="file in files"
                :key="file.id"
                class="flex min-h-11 items-start gap-3 px-4 py-3 transition-colors motion-reduce:transition-none"
                :class="
                    file.usable
                        ? 'cursor-pointer hover:bg-accent/40 has-[:checked]:bg-accent/60'
                        : 'cursor-not-allowed opacity-60'
                "
            >
                <input
                    type="radio"
                    name="shared-file"
                    class="mt-1 size-4 shrink-0 accent-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-card focus-visible:outline-none"
                    :value="file.id"
                    :checked="selected === file.id"
                    :disabled="!file.usable"
                    @change="emit('update:selected', file.id)"
                />
                <span class="min-w-0 flex-1">
                    <span class="block font-mono text-sm break-all">{{
                        file.name
                    }}</span>
                    <span
                        class="mt-0.5 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-xs"
                    >
                        <span
                            class="font-mono text-muted-foreground tabular-nums"
                        >
                            {{ formatBytes(file.sizeBytes) }}
                        </span>
                        <span aria-hidden="true" class="text-muted-foreground"
                            >·</span
                        >
                        <span
                            :class="
                                file.visibility === 'pending'
                                    ? 'text-warn'
                                    : 'text-ok'
                            "
                        >
                            {{ visibilityLabel(file) }}
                        </span>
                    </span>
                    <span
                        v-if="!file.usable"
                        class="mt-0.5 block text-xs text-muted-foreground"
                    >
                        Kein Archiv — lade den entpackten Spielordner als
                        {{ archiveExtensions.join(' oder ') }} hoch.
                    </span>
                </span>
            </label>
        </div>

        <p
            v-if="error"
            id="share-file-error"
            class="px-4 pt-2 pb-3 text-sm text-destructive"
        >
            {{ error }}
        </p>
    </fieldset>
</template>
