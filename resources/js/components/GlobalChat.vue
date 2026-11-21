<script setup lang="ts">
import { MessageCircle, X } from '@lucide/vue';
import { ref } from 'vue';
import HomeChat from '@/components/home/HomeChat.vue';
import type { HomeChatData } from '@/types';

/**
 * The chat is not a page: the button is visible on every participant page and
 * the panel keeps the same conversation as the start page. The data is fetched
 * once, on first open, so the rest of the app does not pay for it.
 */
const open = ref(false);
const chat = ref<HomeChatData | null>(null);
const loading = ref(false);
const failed = ref(false);

async function load(): Promise<void> {
    if (chat.value !== null || loading.value) {
        return;
    }

    loading.value = true;
    failed.value = false;

    try {
        const response = await fetch('/chat', {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });

        if (!response.ok) {
            throw new Error(`HTTP ${response.status}`);
        }

        chat.value = (await response.json()) as HomeChatData;
    } catch {
        failed.value = true;
    } finally {
        loading.value = false;
    }
}

function toggle(): void {
    open.value = !open.value;

    if (open.value) {
        void load();
    }
}
</script>

<template>
    <div
        class="fixed right-4 bottom-5 z-50 flex flex-col items-end gap-3 sm:right-6"
    >
        <section
            v-if="open"
            id="global-chat-panel"
            class="flex h-[min(72vh,34rem)] w-[min(92vw,24rem)] flex-col overflow-hidden rounded-lg border border-border bg-card shadow-2xl"
            aria-label="LANoMAT Chat"
        >
            <header
                class="flex items-center justify-between gap-3 border-b border-border px-4 py-3"
            >
                <h2 class="text-sm font-semibold text-foreground">
                    LANoMAT Chat
                </h2>
                <button
                    type="button"
                    class="rounded-md p-1.5 text-muted-foreground transition-colors hover:bg-accent hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    aria-label="Chat schließen"
                    @click="open = false"
                >
                    <X class="size-4" aria-hidden="true" />
                </button>
            </header>

            <div class="min-h-0 flex-1">
                <div
                    v-if="loading"
                    class="flex h-full items-center justify-center p-6 text-sm text-muted-foreground"
                    role="status"
                >
                    Chat wird geladen …
                </div>

                <div
                    v-else-if="failed"
                    class="flex h-full flex-col items-center justify-center gap-3 p-6 text-center"
                    role="alert"
                >
                    <p class="text-sm text-muted-foreground">
                        Der Chat antwortet gerade nicht.
                    </p>
                    <button
                        type="button"
                        class="rounded-md border border-border px-3 py-1.5 text-sm font-medium transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @click="load"
                    >
                        Erneut versuchen
                    </button>
                </div>

                <HomeChat v-else-if="chat" :chat="chat" compact />
            </div>
        </section>

        <button
            type="button"
            class="inline-flex items-center gap-2 rounded-full border border-border bg-card px-4 py-3 text-sm font-medium text-foreground shadow-lg transition-colors hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
            :aria-expanded="open"
            aria-controls="global-chat-panel"
            @click="toggle"
        >
            <X v-if="open" class="size-5" aria-hidden="true" />
            <MessageCircle v-else class="size-5" aria-hidden="true" />
            {{ open ? 'Chat schließen' : 'Chat' }}
        </button>
    </div>
</template>
