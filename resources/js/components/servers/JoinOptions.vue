<script setup lang="ts">
import { Check, Copy } from '@lucide/vue';
import { onUnmounted, ref } from 'vue';
import Ut2004HandlerDialog from '@/components/servers/Ut2004HandlerDialog.vue';
import { Button } from '@/components/ui/button';
import {
    choosePreferredJoinOption,
    useJoinOption,
} from '@/composables/useJoinOption';
import type { JoinOptionDto } from '@/types';

/**
 * Join block for servers with several ways in (Steam / native install): a
 * segmented choice, one primary "Beitreten" action and "Adresse kopieren".
 * The last choice is remembered per browser (localStorage, optional). The
 * option URLs come from the server (JoinLinkTemplates); this component never
 * builds a URL itself and only follows schemes on a strict allowlist.
 *
 * Rendered only when `options` is non-empty; callers keep their existing
 * connect button for servers without options.
 */
const props = withDefaults(
    defineProps<{
        options: JoinOptionDto[];
        address: string;
        /** `gameservers.join_ui` labels. */
        labels: Record<string, string>;
        /** Shows the ut2004:// link-handler help next to the native option. */
        handlerHelp?: boolean;
        /** Amber "Beitreten" button; false renders it as an outline button. */
        primary?: boolean;
    }>(),
    {
        primary: true,
    },
);

const { safeOptions, selected } = useJoinOption(() => props.options);

function choose(key: string) {
    choosePreferredJoinOption(key);
}

const copyState = ref<'idle' | 'ok' | 'failed'>('idle');
let timer: ReturnType<typeof setTimeout> | undefined;

async function copyAddress() {
    try {
        await navigator.clipboard.writeText(props.address);
        copyState.value = 'ok';
    } catch {
        copyState.value = 'failed';
    }

    clearTimeout(timer);
    timer = setTimeout(() => {
        copyState.value = 'idle';
    }, 1800);
}

onUnmounted(() => clearTimeout(timer));

const helpOpen = ref(false);
</script>

<template>
    <div v-if="selected" class="space-y-2">
        <div class="flex flex-wrap items-center gap-2">
            <div
                role="radiogroup"
                :aria-label="labels.choose"
                class="inline-flex rounded-md border border-border bg-muted/30 p-0.5"
            >
                <button
                    v-for="option in safeOptions"
                    :key="option.key"
                    type="button"
                    role="radio"
                    :aria-checked="option.key === selected.key"
                    class="rounded-sm px-3 py-1 text-sm font-medium text-muted-foreground transition-colors focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-checked:bg-background aria-checked:text-foreground aria-checked:shadow-xs motion-reduce:transition-none"
                    @click="choose(option.key)"
                >
                    {{ option.label }}
                </button>
            </div>

            <Button
                as-child
                size="sm"
                :variant="primary ? 'default' : 'outline'"
            >
                <a :href="selected.url">{{ labels.join }}</a>
            </Button>

            <Button
                type="button"
                size="sm"
                variant="outline"
                @click="copyAddress"
            >
                <Check v-if="copyState === 'ok'" class="size-3.5 text-ok" />
                <Copy v-else class="size-3.5" />
                {{
                    copyState === 'ok'
                        ? labels.copied
                        : copyState === 'failed'
                          ? labels.copy_failed
                          : labels.copy_address
                }}
            </Button>
            <span class="sr-only" aria-live="polite">{{
                copyState === 'ok' ? labels.copied : ''
            }}</span>
        </div>

        <p class="text-xs text-muted-foreground">
            {{ selected.hint }}
            <button
                v-if="handlerHelp && selected.key === 'native'"
                type="button"
                class="underline underline-offset-2 hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                @click="helpOpen = true"
            >
                ({{ labels.setup_link }})
            </button>
        </p>
        <p class="text-xs text-muted-foreground">
            {{ labels.browser_prompt }} {{ labels.untested }}
        </p>

        <Ut2004HandlerDialog
            v-if="handlerHelp"
            v-model:open="helpOpen"
            :labels="labels"
        />
    </div>
</template>
