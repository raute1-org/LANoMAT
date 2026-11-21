<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { useId } from 'vue';

withDefaults(
    defineProps<{
        title: string;
        link?: { href: string; label: string };
        /** Heading level of the title: 2 on a page root, 3 below a round/section h2. */
        level?: 2 | 3;
    }>(),
    { level: 2 },
);

const headingId = useId();
</script>

<template>
    <section
        class="rounded-md border border-border bg-card"
        :aria-labelledby="headingId"
    >
        <div class="flex items-baseline justify-between gap-3 px-4 pt-3 pb-2">
            <component
                :is="`h${level}`"
                :id="headingId"
                class="text-sm font-medium text-muted-foreground"
            >
                {{ title }}
            </component>
            <Link
                v-if="link"
                :href="link.href"
                class="rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            >
                {{ link.label }}
            </Link>
        </div>
        <slot />
    </section>
</template>
