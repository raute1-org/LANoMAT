<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { ArrowLeft } from '@lucide/vue';
import { computed } from 'vue';
import type { PageWidth } from '@/lib/pageWidths';
import { pageWidthClasses } from '@/lib/pageWidths';

type Props = {
    /** Content-width tier — see docs/design.md "Page anatomy & layout rules". */
    width?: PageWidth;
    /** Renders the standard `<h1>`; omit for pages with a bespoke hero header. */
    title?: string;
    /** One-line description directly below the title. */
    description?: string;
    /** In-page back link to the parent list/detail ("Zurück zum Event"). */
    back?: { href: string; label: string };
    /** `main` only for guest-reachable pages rendered without the app shell. */
    as?: 'div' | 'main';
};

const props = withDefaults(defineProps<Props>(), {
    width: 'default',
    as: 'div',
});

const widthClass = computed(() => pageWidthClasses[props.width]);
</script>

<template>
    <component
        :is="as"
        :class="['w-full px-4 py-8 sm:px-6 lg:px-8', widthClass]"
    >
        <div v-if="back || title || $slots.header || $slots.actions">
            <Link
                v-if="back"
                :href="back.href"
                class="mb-4 inline-flex items-center gap-1.5 rounded-sm text-sm text-muted-foreground transition-colors outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
            >
                <ArrowLeft class="size-4" aria-hidden="true" />
                {{ back.label }}
            </Link>

            <div class="flex flex-wrap items-start justify-between gap-4">
                <div class="min-w-0 space-y-1">
                    <slot name="header">
                        <h1
                            v-if="title"
                            class="text-2xl font-semibold tracking-tight text-foreground"
                        >
                            {{ title }}
                        </h1>
                        <p
                            v-if="description"
                            class="text-sm text-muted-foreground"
                        >
                            {{ description }}
                        </p>
                    </slot>
                </div>
                <div
                    v-if="$slots.actions"
                    class="flex flex-wrap items-center gap-2"
                >
                    <slot name="actions" />
                </div>
            </div>
        </div>

        <slot />
    </component>
</template>
