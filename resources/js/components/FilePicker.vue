<script setup lang="ts">
import { computed, ref } from 'vue';
import { buttonVariants } from '@/components/ui/button';

// Shared German file picker for every upload form. The native input stays in
// the DOM (visually hidden, still focusable and form-validated) so the
// browser-locale "Choose Files / No file chosen" chrome never shows; a
// button-styled label opens it and a status line names the selection.
const props = withDefaults(
    defineProps<{
        id: string;
        accept?: string;
        multiple?: boolean;
        required?: boolean;
        buttonLabel?: string;
    }>(),
    { buttonLabel: undefined },
);

const emit = defineEmits<{ change: [event: Event] }>();

const names = ref<string[]>([]);

const status = computed(() => {
    if (names.value.length === 0) {
        return props.multiple
            ? 'Keine Dateien ausgewählt'
            : 'Keine Datei ausgewählt';
    }

    return names.value.length === 1
        ? names.value[0]
        : `${names.value.length} Dateien ausgewählt`;
});

function onChange(event: Event): void {
    const input = event.target as HTMLInputElement;
    names.value = Array.from(input.files ?? []).map((file) => file.name);
    emit('change', event);
}
</script>

<template>
    <div class="flex flex-wrap items-center gap-3">
        <input
            :id="id"
            type="file"
            class="peer sr-only"
            :accept="accept"
            :multiple="multiple"
            :required="required"
            @change="onChange"
        />
        <label
            :for="id"
            :class="[
                buttonVariants({ variant: 'outline' }),
                'cursor-pointer peer-focus-visible:border-ring peer-focus-visible:ring-[3px] peer-focus-visible:ring-ring/50',
            ]"
        >
            {{
                buttonLabel ??
                (multiple ? 'Dateien auswählen' : 'Datei auswählen')
            }}
        </label>
        <span
            class="min-w-0 truncate text-sm text-muted-foreground"
            aria-live="polite"
            >{{ status }}</span
        >
    </div>
</template>
