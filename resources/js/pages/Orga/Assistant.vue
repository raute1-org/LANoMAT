<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import { History, Sparkles } from '@lucide/vue';
import { computed, nextTick, reactive, ref, watch } from 'vue';
import ApprovalCard from '@/components/assistant/ApprovalCard.vue';
import Composer from '@/components/assistant/Composer.vue';
import ConversationList from '@/components/assistant/ConversationList.vue';
import MessageStream from '@/components/assistant/MessageStream.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Skeleton } from '@/components/ui/skeleton';
import { usePageLoadState } from '@/composables/usePageLoadState';
import {
    applyStreamPart,
    isToolPart,
    withUserMessage,
} from '@/lib/assistantMessages';
import {
    AssistantRequestError,
    deleteRequest,
    streamRequest,
} from '@/lib/assistantStream';
import { chat, decide, destroy, index, show } from '@/routes/orga/assistant';
import type {
    AssistantProps,
    ConversationSummary,
    Decision,
    UiMessage,
} from '@/types/assistant';

const props = defineProps<AssistantProps>();

const { isNavigating } = usePageLoadState();

const messages = ref<UiMessage[]>(props.messages);
const draft = ref('');
const streaming = ref(false);
const errorText = ref<string | null>(null);
const lastSent = ref<string | null>(null);
const historyOpen = ref(false);
const composer = ref<InstanceType<typeof Composer> | null>(null);

// Decisions made on cards while other cards of the same turn are still open.
const choices = reactive<Record<string, Decision>>({});
const secrets = reactive<Record<string, string>>({});
const superseded = ref<Set<string>>(new Set());
const toDelete = ref<ConversationSummary | null>(null);

const cloud = computed(() => props.provider.name !== 'ollama');
const providerLabel = computed(() =>
    props.provider.name === 'ollama' ? 'Ollama (lokal)' : 'Claude (Cloud)',
);
const pendingCards = computed(() =>
    props.pending.filter(
        (card) => card.status === 'proposed' || card.status === 'approved',
    ),
);
const openCards = computed(() =>
    pendingCards.value.filter((card) => !superseded.value.has(card.callId)),
);

watch(
    () => props.messages,
    (next) => {
        if (!streaming.value) {
            messages.value = next;
        }
    },
);

watch(
    () => props.conversationId,
    () => {
        Object.keys(choices).forEach((key) => delete choices[key]);
        Object.keys(secrets).forEach((key) => delete secrets[key]);
        superseded.value = new Set();
        errorText.value = null;
        historyOpen.value = false;
    },
);

const examples = [
    'Welche Events und Turniere gibt es gerade?',
    'Lege ein CS2-Wingman-Turnier für Samstag 14:00 an, 16 Teams, Check-in ab 13:30.',
    'Starte einen CS2-Server aus dem Competitive-Preset für das Test-Event.',
];

function useExample(text: string): void {
    draft.value = text;
    void nextTick(() => composer.value?.focus());
}

function messageFor(error: unknown): string {
    if (error instanceof AssistantRequestError) {
        if (error.status === 429) {
            return 'Zu viele Anfragen. Warte einen Moment und sende dann erneut.';
        }

        if (error.status === 403 || error.status === 404) {
            return 'Diese Unterhaltung ist nicht mehr verfügbar. Starte eine neue.';
        }

        if (error.status === 422) {
            return error.message;
        }
    }

    return 'Der Assistent antwortet gerade nicht. Prüfe die Verbindung und sende die Nachricht erneut.';
}

/** Reloads the saved conversation; the stored history is the source of truth after every turn. */
function refresh(id: string | null): void {
    const target = id ?? props.conversationId;

    router.visit(target ? show(target).url : index().url, {
        only: ['conversations', 'conversationId', 'messages', 'pending'],
        preserveScroll: true,
        preserveState: true,
        replace: true,
        onFinish: () => {
            messages.value = props.messages;
        },
    });
}

async function runStream(
    url: string,
    body: Record<string, unknown>,
): Promise<void> {
    streaming.value = true;
    errorText.value = null;
    let id: string | null = null;

    try {
        id = await streamRequest(url, body, (part) => {
            if (part.type === 'error') {
                errorText.value =
                    'Der Modell-Anbieter hat einen Fehler gemeldet. Sende die Nachricht erneut.';

                return;
            }

            messages.value = applyStreamPart(messages.value, part);
        });
    } catch (error) {
        errorText.value = messageFor(error);
    } finally {
        streaming.value = false;
        refresh(id);
    }
}

async function send(retry = false): Promise<void> {
    const text = retry ? (lastSent.value ?? '') : draft.value.trim();

    if (text === '' || streaming.value) {
        return;
    }

    lastSent.value = text;
    draft.value = '';
    // The server closes every open card with a new message; show that right away.
    superseded.value = new Set([
        ...superseded.value,
        ...pendingCards.value.map((card) => card.callId),
    ]);
    messages.value = withUserMessage(messages.value, text);

    await runStream(chat().url, {
        message: text,
        conversation_id: props.conversationId,
    });
}

async function submitDecisions(
    picked: Record<string, Decision>,
): Promise<void> {
    if (!props.conversationId || streaming.value) {
        return;
    }

    const body: Record<string, Record<string, string>> = {
        decisions: { ...picked },
        secrets: {},
    };

    for (const card of openCards.value) {
        if (picked[card.callId] === 'approve' && card.preview.needsSecret) {
            body.secrets[card.callId] = secrets[card.callId] ?? '';
        }
    }

    // Show what is about to run: approved calls work, rejected ones are closed.
    messages.value = messages.value.map((message) => ({
        ...message,
        parts: message.parts.map((part) => {
            if (isToolPart(part) && picked[part.toolCallId] !== undefined) {
                return {
                    ...part,
                    state:
                        picked[part.toolCallId] === 'approve'
                            ? ('input-available' as const)
                            : ('output-denied' as const),
                };
            }

            return part;
        }),
    }));

    const submitted = Object.keys(picked);
    await runStream(decide(props.conversationId).url, body);
    submitted.forEach((callId) => {
        delete choices[callId];
        delete secrets[callId];
    });
}

function onDecide(callId: string, decision: Decision): void {
    const cards = openCards.value;

    if (cards.length <= 1) {
        void submitDecisions({ [callId]: decision });

        return;
    }

    choices[callId] = decision;

    // One resume answers the whole turn: send once every card has a pick.
    if (cards.every((card) => choices[card.callId] !== undefined)) {
        void submitDecisions({ ...choices });
    }
}

function onUndo(callId: string): void {
    delete choices[callId];
}

async function removeConversation(): Promise<void> {
    const target = toDelete.value;
    toDelete.value = null;

    if (!target) {
        return;
    }

    try {
        await deleteRequest(destroy(target.id).url);
    } catch {
        errorText.value = 'Die Unterhaltung konnte nicht gelöscht werden.';

        return;
    }

    router.visit(
        target.id === props.conversationId ? index().url : window.location.href,
        { preserveScroll: true },
    );
}

const empty = computed(
    () =>
        messages.value.length === 0 && !streaming.value && !isNavigating.value,
);
</script>

<template>
    <Head title="Assistent" />

    <PageContainer
        width="wide"
        title="Assistent"
        :description="`Liest den Stand und bereitet Änderungen vor. Nichts passiert, bevor du es auf der Karte bestätigst. Anbieter: ${providerLabel}.`"
    >
        <template #actions>
            <Button
                type="button"
                variant="outline"
                size="sm"
                class="lg:hidden"
                :aria-expanded="historyOpen"
                aria-controls="assistant-history"
                @click="historyOpen = !historyOpen"
            >
                <History class="size-4" aria-hidden="true" />
                Verlauf
            </Button>
        </template>

        <div class="mt-6 grid gap-4 lg:grid-cols-[15rem_minmax(0,1fr)]">
            <aside
                id="assistant-history"
                :class="historyOpen ? 'block' : 'hidden lg:block'"
            >
                <ConversationList
                    :conversations="conversations"
                    :active-id="conversationId"
                    @remove="toDelete = $event"
                />
            </aside>

            <section
                class="flex h-[min(78svh,44rem)] min-h-[26rem] flex-col overflow-hidden rounded-md border border-border bg-card"
                aria-label="Chat mit dem Assistenten"
            >
                <div
                    v-if="isNavigating"
                    class="flex-1 space-y-3 p-4"
                    role="status"
                    aria-label="Unterhaltung wird geladen"
                >
                    <Skeleton class="h-8 w-2/3" />
                    <Skeleton class="ml-auto h-8 w-1/2" />
                    <Skeleton class="h-16 w-3/4" />
                </div>

                <div
                    v-else-if="empty"
                    class="flex flex-1 flex-col justify-center gap-4 p-6"
                >
                    <div class="space-y-1">
                        <h2
                            class="flex items-center gap-2 text-base font-semibold text-foreground"
                        >
                            <Sparkles
                                class="size-4 text-muted-foreground"
                                aria-hidden="true"
                            />
                            Was soll LANoMAT erledigen?
                        </h2>
                        <p class="text-sm text-muted-foreground">
                            Schreibe, was du brauchst. Änderungen erscheinen als
                            Karte mit Ausführen und Ablehnen.
                        </p>
                    </div>
                    <ul class="space-y-2">
                        <li v-for="example in examples" :key="example">
                            <button
                                type="button"
                                class="w-full rounded-md border border-dashed border-border px-3 py-2 text-left text-sm text-foreground transition-colors outline-none hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring"
                                @click="useExample(example)"
                            >
                                {{ example }}
                            </button>
                        </li>
                    </ul>
                </div>

                <MessageStream
                    v-else
                    :messages="messages"
                    :streaming="streaming"
                />

                <div
                    v-if="openCards.length > 0 || pendingCards.length > 0"
                    class="max-h-[45%] space-y-3 overflow-y-auto border-t border-border bg-background/40 p-3"
                >
                    <ApprovalCard
                        v-for="card in pendingCards"
                        :key="card.callId"
                        :card="card"
                        :chosen="choices[card.callId] ?? null"
                        :secret="secrets[card.callId] ?? ''"
                        :busy="streaming"
                        :superseded="superseded.has(card.callId)"
                        @update:secret="secrets[card.callId] = $event"
                        @decide="onDecide"
                        @undo="onUndo"
                    />
                </div>

                <div
                    v-if="errorText"
                    class="flex flex-wrap items-center justify-between gap-2 border-t border-destructive/50 bg-destructive/10 px-4 py-3 text-sm text-destructive"
                    role="alert"
                >
                    <span>{{ errorText }}</span>
                    <Button
                        v-if="lastSent"
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="send(true)"
                    >
                        Erneut senden
                    </Button>
                </div>

                <Composer
                    ref="composer"
                    v-model="draft"
                    :disabled="streaming"
                    :cloud="cloud"
                    @send="send()"
                />
            </section>
        </div>

        <Dialog :open="toDelete !== null" @update:open="toDelete = null">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Unterhaltung löschen?</DialogTitle>
                    <DialogDescription>
                        „{{ toDelete?.title }}“ wird mit allen Nachrichten
                        gelöscht. Das Protokoll der ausgeführten Aktionen bleibt
                        erhalten.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="toDelete = null"
                    >
                        Abbrechen
                    </Button>
                    <Button
                        type="button"
                        variant="destructive"
                        @click="removeConversation"
                    >
                        Löschen
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </PageContainer>
</template>
