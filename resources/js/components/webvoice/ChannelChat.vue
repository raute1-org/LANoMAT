<script setup lang="ts">
import { Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, toRef, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { useChannelChat } from '@/composables/useChannelChat';

const props = defineProps<{
    channelId: number;
    maxLength: number;
    pageSize: number;
    canModerate: boolean;
    myId: number;
    labels: Record<string, string>;
}>();

const channelId = toRef(props, 'channelId');
const {
    messages,
    loading,
    failure,
    hasOlder,
    sending,
    send,
    remove,
    loadOlder,
    reload,
} = useChannelChat(channelId, props.pageSize);

const draft = ref('');
const scroller = ref<HTMLElement | null>(null);

const failureText = computed(() => {
    switch (failure.value) {
        case 'load':
            return props.labels.chat_error;
        case 'rate':
            return props.labels.chat_rate;
        case 'send':
            return props.labels.chat_failed;
        default:
            return null;
    }
});

const remaining = computed(() => props.maxLength - draft.value.length);

const timeFormat = new Intl.DateTimeFormat('de-DE', {
    hour: '2-digit',
    minute: '2-digit',
    timeZone: 'Europe/Berlin',
});

function time(iso: string): string {
    return timeFormat.format(new Date(iso));
}

async function submit(): Promise<void> {
    const body = draft.value.trim();

    if (body === '' || body.length > props.maxLength || sending.value) {
        return;
    }

    if (await send(body)) {
        draft.value = '';
    }
}

// Stick to the newest message, unless the reader scrolled up to older ones.
watch(
    () => messages.value.at(-1)?.id,
    async () => {
        const el = scroller.value;
        const nearBottom =
            !el || el.scrollHeight - el.scrollTop - el.clientHeight < 80;

        await nextTick();

        if (el && nearBottom) {
            el.scrollTop = el.scrollHeight;
        }
    },
);
</script>

<template>
    <section
        class="rounded-md border border-border bg-card p-5"
        :aria-label="labels.chat"
    >
        <div class="flex items-center justify-between gap-3">
            <h2 class="text-lg font-semibold tracking-tight">
                {{ labels.chat }}
            </h2>
            <span class="text-xs text-muted-foreground">{{
                labels.live_hint
            }}</span>
        </div>

        <div
            ref="scroller"
            class="mt-4 max-h-96 min-h-40 overflow-y-auto"
            role="log"
            aria-live="polite"
            tabindex="0"
        >
            <!-- loading -->
            <div v-if="loading && messages.length === 0" class="space-y-3">
                <span class="sr-only">{{ labels.chat_loading }}</span>
                <Skeleton class="h-10 w-3/4" />
                <Skeleton class="h-10 w-1/2" />
                <Skeleton class="h-10 w-2/3" />
            </div>

            <!-- error -->
            <div
                v-else-if="failure === 'load' && messages.length === 0"
                role="alert"
                class="rounded-md border border-destructive/50 bg-destructive/10 p-6 text-center text-sm text-destructive"
            >
                <p>{{ labels.chat_error }}</p>
                <Button
                    class="mt-3"
                    size="sm"
                    variant="outline"
                    @click="reload()"
                >
                    {{ labels.retry }}
                </Button>
            </div>

            <!-- empty -->
            <p
                v-else-if="messages.length === 0"
                class="rounded-md border border-dashed border-border p-6 text-sm text-muted-foreground"
            >
                {{ labels.chat_empty }}
            </p>

            <!-- normal -->
            <ul v-else class="space-y-3">
                <li v-if="hasOlder" class="text-center">
                    <Button
                        size="sm"
                        variant="ghost"
                        :disabled="loading"
                        @click="loadOlder()"
                    >
                        {{ labels.chat_older }}
                    </Button>
                </li>
                <li
                    v-for="message in messages"
                    :key="message.id"
                    class="group flex items-start justify-between gap-3"
                >
                    <div class="min-w-0">
                        <p class="text-sm">
                            <span class="font-medium">{{
                                message.author.name
                            }}</span>
                            <span
                                class="ml-2 font-mono text-xs text-muted-foreground tabular-nums"
                                >{{ time(message.created_at) }}</span
                            >
                        </p>
                        <!-- Text node on purpose: message bodies are never rendered as HTML. -->
                        <p class="text-sm break-words whitespace-pre-wrap">
                            {{ message.body }}
                        </p>
                    </div>
                    <Button
                        v-if="canModerate"
                        size="icon"
                        variant="ghost"
                        class="shrink-0 focus-visible:opacity-100 sm:opacity-0 sm:group-hover:opacity-100"
                        :title="labels.chat_delete"
                        :aria-label="labels.chat_delete"
                        @click="remove(message.id)"
                    >
                        <Trash2 class="size-4" aria-hidden="true" />
                    </Button>
                </li>
            </ul>
        </div>

        <form class="mt-4 flex items-start gap-2" @submit.prevent="submit">
            <div class="min-w-0 flex-1">
                <Input
                    v-model="draft"
                    :placeholder="labels.chat_placeholder"
                    :aria-label="labels.chat_placeholder"
                    autocomplete="off"
                    :aria-invalid="remaining < 0"
                />
                <p
                    v-if="remaining < 50"
                    class="mt-1 font-mono text-xs tabular-nums"
                    :class="
                        remaining < 0
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ remaining }}
                </p>
                <p
                    v-if="failureText && failure !== 'load'"
                    role="alert"
                    class="mt-1 text-sm text-destructive"
                >
                    {{ failureText }}
                </p>
            </div>
            <Button
                type="submit"
                :disabled="sending || draft.trim() === '' || remaining < 0"
            >
                {{ labels.chat_send }}
            </Button>
        </form>
    </section>
</template>
