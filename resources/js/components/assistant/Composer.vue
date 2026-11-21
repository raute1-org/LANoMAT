<script setup lang="ts">
import { SendHorizontal } from '@lucide/vue';
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';

const props = defineProps<{
    disabled: boolean;
    /** Cloud providers see tool results; the hint names that. */
    cloud: boolean;
}>();

const text = defineModel<string>({ required: true });
const emit = defineEmits<{ send: [] }>();
const input = ref<InstanceType<typeof Textarea> | null>(null);

function onKeydown(event: KeyboardEvent): void {
    if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
        event.preventDefault();
        submit();
    }
}

function submit(): void {
    if (!props.disabled && text.value.trim() !== '') {
        emit('send');
    }
}

defineExpose({ focus: () => input.value?.$el?.focus?.() });
</script>

<template>
    <form class="border-t border-border p-3" @submit.prevent="submit">
        <div class="flex items-end gap-2">
            <Textarea
                ref="input"
                v-model="text"
                rows="2"
                maxlength="4000"
                class="min-h-[2.75rem] flex-1 resize-none"
                placeholder="Was soll LANoMAT tun?"
                aria-label="Nachricht an den Assistenten"
                :disabled="disabled"
                @keydown="onKeydown"
            />
            <Button
                type="submit"
                :disabled="disabled || text.trim() === ''"
                class="shrink-0"
            >
                <SendHorizontal class="size-4" aria-hidden="true" />
                Senden
            </Button>
        </div>
        <p class="mt-2 text-xs text-muted-foreground">
            Passwörter nie in den Chat, sie werden auf der Karte gesetzt.
            <template v-if="cloud">
                Namen und Daten aus Abfragen gehen an den Modell-Anbieter.
            </template>
        </p>
    </form>
</template>
