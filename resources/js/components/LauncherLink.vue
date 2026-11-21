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

const TRYING_TEXT = 'Öffne den Launcher …';
const OPENED_TEXT =
    'Launcher geöffnet. Schau in die Taskleiste, falls du kein Fenster siehst.';
// The same question the visible notice asks, plus its one-sentence answer.
const UNANSWERED_TEXT =
    'Kein Launcher-Fenster aufgegangen? Dann ist der LANoMAT Launcher auf diesem PC meist noch nicht installiert oder wurde nach der Installation noch nie gestartet.';

/**
 * The one live region this action owns. Everything the page has to say about
 * an attempt goes through here — the visible lines below only mirror it, so a
 * screen reader never misses a state because an element was inserted late.
 */
const announcement = computed<string>(() => {
    if (state.value === 'trying') {
        return TRYING_TEXT;
    }

    if (state.value === 'opened') {
        return OPENED_TEXT;
    }

    return state.value === 'unanswered' ? UNANSWERED_TEXT : '';
});

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

        <!-- One persistent polite region (CopyText pattern): every state is
             announced from here, never from an element inserted later. -->
        <span class="sr-only" role="status" aria-live="polite">{{
            announcement
        }}</span>

        <!-- The attempt is waiting for a takeover. -->
        <span
            v-if="state === 'trying'"
            aria-hidden="true"
            class="font-mono text-[11px] text-muted-foreground"
        >
            {{ TRYING_TEXT }}
        </span>

        <!-- Something took over: a short confirmation, then back to quiet
             (the composable resets to idle after a few seconds). -->
        <span
            v-else-if="state === 'opened'"
            aria-hidden="true"
            class="text-xs text-muted-foreground"
            data-test="launcher-opened"
        >
            {{ OPENED_TEXT }}
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
