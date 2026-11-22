<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { X } from '@lucide/vue';
import { Button } from '@/components/ui/button';

/**
 * What a participant reads when a `lanomat://` click took nobody over: a
 * question, not a verdict. The page cannot know whether the launcher came up
 * (a window that opens behind the browser never takes the focus), so the text
 * holds in both cases: the likely reason when nothing opened, and where to
 * look when it did. It sits directly under the action that was clicked; the
 * announcement itself comes from the parent's one persistent live region, so
 * this block carries no `role="status"` of its own.
 *
 * The box is built from inline-level elements only (`span`, `a`, `button`), so
 * it stays valid inside the small text paragraphs where some deep links live.
 * Amber is not used here: the primary action keeps the one accent.
 */
withDefaults(
    defineProps<{
        /** The manual way on this surface (install command, server address). */
        manualHint?: string;
    }>(),
    { manualHint: undefined },
);

const emit = defineEmits<{
    retry: [];
    dismiss: [];
}>();
</script>

<template>
    <span
        class="block w-64 max-w-full rounded-md border border-border bg-muted/50 p-3 text-xs"
        data-test="launcher-unanswered"
    >
        <span class="flex items-start justify-between gap-2">
            <span class="block min-w-0 space-y-1">
                <span class="block font-medium text-foreground"
                    >Kein Launcher-Fenster aufgegangen?</span
                >
                <span class="block text-muted-foreground"
                    >Dann ist der LANoMAT Launcher auf diesem PC meist noch
                    nicht installiert, oder er wurde nach der Installation noch
                    nie gestartet. Läuft er schon, schau in die Taskleiste: Er
                    kann sich hinter dem Browser geöffnet haben.</span
                >
                <span v-if="manualHint" class="block text-muted-foreground">{{
                    manualHint
                }}</span>
            </span>
            <Button
                type="button"
                variant="ghost"
                size="icon-sm"
                class="-mt-1.5 -mr-1.5 shrink-0 text-muted-foreground hover:text-foreground"
                aria-label="Hinweis schließen"
                @click="emit('dismiss')"
            >
                <X class="size-3.5" aria-hidden="true" />
            </Button>
        </span>

        <span class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <Link
                href="/app"
                class="inline-flex min-h-8 items-center rounded-sm font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none pointer-coarse:min-h-11"
            >
                App herunterladen
            </Link>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="pointer-coarse:min-h-11"
                @click="emit('retry')"
            >
                Nochmal versuchen
            </Button>
        </span>
    </span>
</template>
