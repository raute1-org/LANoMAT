<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onBeforeUnmount, ref } from 'vue';
import { Button } from '@/components/ui/button';

const props = defineProps<{ text: string; label: string }>();

const copied = ref(false);
let timer: ReturnType<typeof setTimeout> | undefined;

async function copy() {
    try {
        await navigator.clipboard.writeText(props.text);
        copied.value = true;
        clearTimeout(timer);
        timer = setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        copied.value = false;
    }
}

onBeforeUnmount(() => clearTimeout(timer));
</script>

<template>
    <span class="inline-flex items-center gap-2">
        <Button
            type="button"
            variant="outline"
            size="sm"
            data-test="copy-text"
            @click="copy"
        >
            <component :is="copied ? Check : Copy" class="size-4" />
            {{ label }}
        </Button>
        <span class="sr-only" role="status" aria-live="polite">{{
            copied ? 'Kopiert' : ''
        }}</span>
        <span v-if="copied" aria-hidden="true" class="font-mono text-xs text-ok"
            >Kopiert</span
        >
    </span>
</template>
