<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useDesktopClient } from '@/composables/useDesktopClient';

/**
 * A quiet text link to the desktop app download page, with an optional lead sentence.
 * Renders nothing when the visitor cannot reach the page (guest, WebVoice module off).
 */
defineProps<{
    label: string;
    lead?: string;
}>();

const { client } = useDesktopClient();
const href = computed(() => client.value?.url ?? null);
</script>

<template>
    <p
        v-if="href"
        class="text-sm text-muted-foreground"
        data-test="desktop-client-link"
    >
        <template v-if="lead">{{ lead }} </template>
        <Link
            :href="href"
            class="rounded-sm text-foreground underline underline-offset-4 outline-none hover:text-primary focus-visible:ring-2 focus-visible:ring-ring"
        >
            {{ label }}
        </Link>
    </p>
</template>
