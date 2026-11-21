<script setup lang="ts">
import { nextTick, ref, watch } from 'vue';
import ToolChip from '@/components/assistant/ToolChip.vue';
import { isToolPart } from '@/lib/assistantMessages';
import type { UiMessage } from '@/types/assistant';

const props = defineProps<{ messages: UiMessage[]; streaming: boolean }>();

const scroller = ref<HTMLElement | null>(null);

function scrollToEnd(): void {
    void nextTick(() => {
        const el = scroller.value;

        if (el) {
            el.scrollTop = el.scrollHeight;
        }
    });
}

watch(() => props.messages, scrollToEnd, { deep: true, immediate: true });
</script>

<template>
    <div
        ref="scroller"
        role="log"
        aria-live="polite"
        aria-label="Unterhaltung"
        class="min-h-0 flex-1 space-y-4 overflow-y-auto px-4 py-4"
    >
        <div
            v-for="message in messages"
            :key="message.id"
            :class="message.role === 'user' ? 'flex justify-end' : ''"
        >
            <div
                v-if="message.role === 'user'"
                class="max-w-[85%] rounded-lg bg-muted px-3 py-2 text-sm whitespace-pre-wrap text-foreground"
            >
                <template v-for="(part, i) in message.parts" :key="i">
                    <template v-if="part.type === 'text'">{{
                        part.text
                    }}</template>
                </template>
            </div>

            <div v-else class="max-w-prose space-y-2">
                <template v-for="(part, i) in message.parts" :key="i">
                    <p
                        v-if="part.type === 'text' && part.text !== ''"
                        class="text-sm leading-relaxed whitespace-pre-wrap text-foreground"
                    >
                        {{ part.text }}
                    </p>
                    <div v-else-if="isToolPart(part)">
                        <ToolChip :part="part" />
                    </div>
                </template>
            </div>
        </div>

        <p
            v-if="streaming"
            class="flex items-center gap-1.5 text-sm text-muted-foreground"
            role="status"
        >
            <span class="flex gap-1" aria-hidden="true">
                <span
                    v-for="n in 3"
                    :key="n"
                    class="size-1.5 rounded-full bg-muted-foreground motion-safe:animate-bounce"
                    :style="{ animationDelay: `${(n - 1) * 120}ms` }"
                />
            </span>
            LANoMAT arbeitet …
        </p>
    </div>
</template>
