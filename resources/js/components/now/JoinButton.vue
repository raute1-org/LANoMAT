<script setup lang="ts">
import { ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{
    connectString: string;
    connectIsLink: boolean;
    primary?: boolean;
    label?: string;
}>();

const t = useNowLabels();
const copied = ref(false);

async function copy() {
    try {
        await navigator.clipboard.writeText(props.connectString);
        copied.value = true;
        setTimeout(() => {
            copied.value = false;
        }, 2000);
    } catch {
        // Clipboard may be unavailable (insecure context); the string is
        // shown next to the button where it matters, so fail quietly.
    }
}
</script>

<template>
    <Button
        v-if="connectIsLink"
        as-child
        size="sm"
        :variant="primary ? 'default' : 'outline'"
    >
        <a :href="connectString">{{ label ?? t('join') }}</a>
    </Button>
    <Button
        v-else
        type="button"
        size="sm"
        :variant="primary ? 'default' : 'outline'"
        @click="copy"
    >
        {{ copied ? t('copied') : (label ?? t('join')) }}
    </Button>
</template>
