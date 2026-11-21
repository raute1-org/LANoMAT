<script setup lang="ts">
import { computed, useAttrs } from 'vue';
import LauncherLinkNotice from '@/components/LauncherLinkNotice.vue';
import type { ButtonVariants } from '@/components/ui/button';
import { Button } from '@/components/ui/button';
import { useLauncherLink } from '@/composables/useLauncherLink';
import { cn } from '@/lib/utils';

/**
 * A `lanomat://` action that never ends in silence.
 *
 * The real `<a :href>` stays: the URL is visible on hover, copyable, and works
 * without JavaScript. The click itself is left to the browser (a native
 * anchor navigation keeps the user gesture an external-protocol launch needs);
 * we only watch whether the page loses focus, and if it does not, say so.
 *
 * The visual variant comes through the same Button/as-child pattern the pages
 * already use; `variant="text"` is the quiet inline link some surfaces use.
 */
defineOptions({ inheritAttrs: false });

type ButtonVariant = NonNullable<ButtonVariants['variant']>;
type ButtonSize = NonNullable<ButtonVariants['size']>;

const props = withDefaults(
    defineProps<{
        /** The `lanomat://` URL. */
        href: string;
        variant?: ButtonVariant | 'text';
        size?: ButtonSize;
        /** The manual way on this surface, shown in the notice. */
        manualHint?: string;
    }>(),
    { variant: 'default', size: 'default', manualHint: undefined },
);

const attrs = useAttrs();
const { state, open, retry, dismiss } = useLauncherLink();

const textLink = computed(() =>
    cn(
        'inline-block rounded-sm font-medium text-foreground underline decoration-border underline-offset-2 transition-colors hover:decoration-primary focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none motion-reduce:transition-none',
        typeof attrs.class === 'string' ? attrs.class : undefined,
    ),
);

// The text variant styles its own class (merged above), so the attribute
// object must not carry the page's class a second time.
const restAttrs = computed<Record<string, unknown>>(() => {
    const rest = { ...attrs };
    delete rest.class;

    return rest;
});

function onClick(event: MouseEvent): void {
    // Only a plain primary click is an attempt; a modified click opens a tab or
    // a window and never reaches the operating system's URL handler.
    if (
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    // The anchor navigates on its own; observe, do not fire the scheme twice.
    open(props.href, { navigate: false });
}
</script>

<template>
    <span class="inline-flex max-w-full min-w-0 flex-col items-start gap-1">
        <Button
            v-if="variant !== 'text'"
            as-child
            :variant="variant"
            :size="size"
        >
            <a :href="href" v-bind="$attrs" @click="onClick">
                <slot />
            </a>
        </Button>
        <a
            v-else
            :href="href"
            v-bind="restAttrs"
            :class="textLink"
            @click="onClick"
        >
            <slot />
        </a>

        <!-- One persistent polite region (CopyText pattern): the wait is
             announced even though the visible line is inserted later. The
             notice below carries its own status when nobody answered. -->
        <span class="sr-only" role="status" aria-live="polite">{{
            state === 'trying' ? 'Öffne den Launcher …' : ''
        }}</span>

        <!-- The attempt is waiting for a takeover. -->
        <span
            v-if="state === 'trying'"
            aria-hidden="true"
            class="font-mono text-[11px] text-muted-foreground"
        >
            Öffne den Launcher …
        </span>

        <!-- Nobody answered: say what that usually means and what to do. -->
        <LauncherLinkNotice
            v-else-if="state === 'unanswered'"
            :manual-hint="manualHint"
            @retry="retry"
            @dismiss="dismiss"
        />
    </span>
</template>
