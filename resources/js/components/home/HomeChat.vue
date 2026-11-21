<script setup lang="ts">
/**
 * The start-page chat hero — an arcade cabinet, not a corporate assistant.
 *
 * It shows one big greeting, two giant chips and (after the autoplay demo, a
 * click or reduced motion) large game/server tiles. The "chat" is
 * deterministic browser logic: a tiny keyword matcher answers the two
 * questions the backend prepared data for (`HomeChatData`) — no LLM, no
 * requests, no state beyond this component.
 *
 * The autoplay demo types the two questions quickly and then renders both
 * answer sets. Any focus in the input or a chip click stops it immediately;
 * `prefers-reduced-motion` skips straight to the final state.
 */
import { Link } from '@inertiajs/vue3';
import { ArrowRight } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';

import type { HomeChatData, HomeChatServer } from '@/types';

type Cards = 'games' | 'live' | 'servers' | 'both';

interface ChatMessage {
    id: number;
    role: 'assistant' | 'user';
    text: string;
    cards?: Cards;
}

const props = withDefaults(
    defineProps<{
        chat: HomeChatData;
        /** Guests see the public catalogue only; never event/member data. */
        guest?: boolean;
        /** Compact dock variant: no hero header, no autoplay, fills its panel. */
        compact?: boolean;
        /** Hide the action row when the page already shows the same buttons. */
        showActions?: boolean;
    }>(),
    { guest: false, compact: false, showActions: true },
);

// The autoplay types the very questions the chips offer, so the demo always
// matches the opaque buttons below it (and stays German — the UI is German).
const GAME_QUERY = props.chat.suggestions[0] ?? 'Welche Spiele laufen?';
const SERVER_QUERY = props.chat.suggestions[1] ?? 'Welche Server laufen?';

const messages = ref<ChatMessage[]>([
    { id: 1, role: 'assistant', text: props.chat.greeting },
]);
let nextMessageId = 2;

const draft = ref('');
const demoTyping = ref<string | null>(null);
const demoDone = ref(false);
const transcript = ref<HTMLElement | null>(null);

// The agent feedback the user actually sees between asking and reading:
// "denkt …" for the (very short) match, then a streamed answer with a caret.
// It is intentionally fast — the logic is local, the theatre only softens the
// jump from question to cards.
const agentState = ref<'idle' | 'thinking' | 'writing'>('idle');
const streamingId = ref<number | null>(null);
let answerToken = 0;

/**
 * Keep the newest message in view inside the capped transcript (the panel has a
 * fixed max height so the chips and the input stay above the fold — see
 * docs/design.md "Start-page chat": the transcript scrolls internally). A tall
 * answer (cards) is aligned to its top so it is read from its first line; a
 * short one sits at the bottom of the box.
 */
async function scrollToLatest(): Promise<void> {
    await nextTick();

    const element = transcript.value;

    if (!element) {
        return;
    }

    // Once the answer has cards, anchor the answer (text + cards), not the
    // question: the card is the thing the visitor asked for. Short text-only
    // answers keep the question in view.
    const answers = element.querySelectorAll<HTMLElement>(
        '[data-role="assistant"]',
    );
    const lastAnswer = answers[answers.length - 1];
    const questions =
        element.querySelectorAll<HTMLElement>('[data-role="user"]');
    const anchor =
        (lastAnswer?.querySelector('[data-test^="home-chat-answer-"]')
            ? lastAnswer
            : null) ??
        questions[questions.length - 1] ??
        (element.lastElementChild as HTMLElement | null);

    if (!anchor) {
        return;
    }

    const offset =
        anchor.getBoundingClientRect().top -
        element.getBoundingClientRect().top +
        element.scrollTop;

    element.scrollTop = Math.min(
        offset,
        element.scrollHeight - element.clientHeight,
    );
}

watch(() => messages.value.length, scrollToLatest);

let demoRunning = false;
const timers: ReturnType<typeof setTimeout>[] = [];

const runningCount = computed(
    () =>
        props.chat.servers.filter((server) => server.status === 'ready').length,
);
const runningLabel = computed(() =>
    runningCount.value === 1
        ? '1 Server läuft'
        : `${runningCount.value} Server laufen`,
);

/**
 * "Welche Spiele laufen?" answers with the games that actually have a
 * running server right now — the catalogue below them is a different
 * question. A game without a server is not "live", even if it is published.
 */
const liveGames = computed(() =>
    props.chat.games.filter((game) => (game.server_count ?? 0) > 0),
);

function displayGames(cards: Cards): HomeChatData['games'] {
    return cards === 'live' ? liveGames.value : props.chat.games;
}

const serverVariants: Record<string, 'live' | 'ok' | 'warn' | 'down'> = {
    ready: 'ok',
    provisioning: 'warn',
    pending: 'warn',
    not_responding: 'warn',
    failed: 'down',
    stopped: 'down',
    install_failed: 'down',
    offline: 'down',
};

const serverLabels: Record<string, string> = {
    ready: 'Läuft',
    provisioning: 'Startet',
    pending: 'Wartet',
    not_responding: 'Keine Antwort',
    failed: 'Fehler',
    stopped: 'Gestoppt',
    install_failed: 'Installation fehlgeschlagen',
    offline: 'Offline',
};

function pushMessage(message: Omit<ChatMessage, 'id'>): void {
    messages.value.push({ id: nextMessageId++, ...message });
}

function later(ms: number, callback: () => void): void {
    timers.push(setTimeout(callback, ms));
}

function clearTimers(): void {
    timers.forEach((timer) => clearTimeout(timer));
    timers.length = 0;
}

function delay(ms: number): Promise<void> {
    return new Promise((resolve) => later(ms, resolve));
}

/** "Denkt …", then streams the local answer — no request, no spinner dead air. */
async function respond(text: string): Promise<void> {
    const token = ++answerToken;
    const answer = answerFor(text);

    agentState.value = 'thinking';

    await delay(prefersReducedMotion() ? 140 : 320);

    if (token !== answerToken) {
        return;
    }

    messages.value.push({
        id: nextMessageId++,
        role: 'assistant',
        text: '',
    });

    const message = messages.value[messages.value.length - 1];

    if (!message) {
        agentState.value = 'idle';

        return;
    }

    streamingId.value = message.id;
    agentState.value = 'writing';

    if (prefersReducedMotion()) {
        message.text = answer.text;
    } else {
        const chars = Array.from(answer.text);

        for (let index = 3; index <= chars.length; index += 3) {
            await delay(13);

            if (token !== answerToken) {
                return;
            }

            message.text = chars.slice(0, index).join('');
        }

        message.text = answer.text;
    }

    if (token !== answerToken) {
        return;
    }

    message.cards = answer.cards;
    streamingId.value = null;
    agentState.value = 'idle';
    void scrollToLatest();
}

/** The demo's final state: the typed question plus both answer sets. */
function finishDemo(): void {
    demoRunning = false;
    clearTimers();
    demoTyping.value = null;
    agentState.value = 'idle';
    streamingId.value = null;

    if (demoDone.value) {
        return;
    }

    demoDone.value = true;

    const live = liveGames.value;

    pushMessage({ role: 'user', text: GAME_QUERY });
    pushMessage({
        role: 'assistant',
        text:
            live.length > 0
                ? 'Diese Spiele laufen gerade:'
                : 'Gerade läuft kein Spiel. Diese Spiele gibt es auf der LAN:',
        cards: live.length > 0 ? 'live' : 'games',
    });
    pushMessage({ role: 'user', text: SERVER_QUERY });
    pushMessage({
        role: 'assistant',
        text:
            props.chat.servers.length > 0
                ? 'Diese Server laufen gerade:'
                : 'Gerade läuft kein Server.',
        cards: 'servers',
    });

    void scrollToLatest();
}

/** Stop the autoplay because the visitor is interacting. */
function skipDemo(): void {
    if (!demoDone.value) {
        finishDemo();
    }
}

/** Stop the autoplay silently (the visitor's own question answers it). */
function cancelDemo(): void {
    demoRunning = false;
    clearTimers();
    demoTyping.value = null;
}

function typeText(text: string, index: number, done: () => void): void {
    if (!demoRunning) {
        return;
    }

    demoTyping.value = text.slice(0, index);

    if (index >= text.length) {
        done();

        return;
    }

    // Quick, arcade-cabinet pace — no slow "AI is thinking" theatre.
    later(24, () => typeText(text, index + 1, done));
}

function eraseText(text: string, length: number, done: () => void): void {
    if (!demoRunning) {
        return;
    }

    demoTyping.value = text.slice(0, length);

    if (length <= 0) {
        done();

        return;
    }

    later(11, () => eraseText(text, length - 1, done));
}

function startDemo(): void {
    demoRunning = true;

    later(220, () =>
        typeText(GAME_QUERY, 0, () =>
            later(260, () =>
                eraseText(GAME_QUERY, GAME_QUERY.length, () =>
                    later(160, () =>
                        typeText(SERVER_QUERY, 0, () => later(320, finishDemo)),
                    ),
                ),
            ),
        ),
    );
}

function prefersReducedMotion(): boolean {
    return (
        typeof window !== 'undefined' &&
        window.matchMedia('(prefers-reduced-motion: reduce)').matches
    );
}

/** Lowercase, diacritics folded, so "läuft"/"laeuft"/"lauft" all match. */
function normalize(value: string): string {
    return value
        .toLowerCase()
        .normalize('NFD')
        .replace(/[\u0300-\u036f]/g, '');
}

type Intent = 'games' | 'live' | 'servers' | 'help';

function intents(text: string): Intent[] {
    const value = normalize(text);
    const found: Intent[] = [];

    if (/(spiel|game|zock|install|download)/.test(value)) {
        // "Welche Spiele laufen?" is a live question; "Was gibt es zu
        // spielen?" is the catalogue.
        found.push(
            /(lauf|live|gerade|jetzt|online)/.test(value) ? 'live' : 'games',
        );
    }

    if (/(server|running|beitret|join)/.test(value)) {
        found.push('servers');
    }

    if (
        found.length === 0 &&
        /(hilfe|help|was kannst|wie funktioniert)/.test(value)
    ) {
        return ['help'];
    }

    return found;
}

function answerFor(text: string): Omit<ChatMessage, 'id'> {
    const found = intents(text);

    if (found.length === 0) {
        return {
            role: 'assistant',
            text: 'Das habe ich nicht verstanden. Tippe auf eine der beiden Fragen — mehr braucht es nicht.',
        };
    }

    if (found.length === 2) {
        return {
            role: 'assistant',
            text: 'Spiele und Server — hier ist beides:',
            cards: 'both',
        };
    }

    if (found[0] === 'help') {
        return {
            role: 'assistant',
            text: 'Frage mich nach Spielen oder laufenden Servern. Ich antworte ohne KI, direkt aus dem LAN.',
        };
    }

    if (found[0] === 'live') {
        const live = liveGames.value;

        return {
            role: 'assistant',
            text:
                live.length > 0
                    ? 'Diese Spiele laufen gerade:'
                    : props.chat.games.length > 0
                      ? 'Gerade läuft kein Spiel. Diese Spiele gibt es auf der LAN:'
                      : props.guest
                        ? 'Gerade läuft kein Spiel. Melde dich an, dann siehst du die Spiele-Bibliothek.'
                        : 'Gerade läuft kein Spiel und es sind keine Spiele freigegeben.',
            cards: live.length > 0 ? 'live' : 'games',
        };
    }

    if (found[0] === 'games') {
        return {
            role: 'assistant',
            text:
                props.chat.games.length > 0
                    ? props.guest
                        ? 'Diese Spiele gibt es auf der LAN:'
                        : 'Diese Spiele stehen bereit:'
                    : props.guest
                      ? 'Noch keine Spiele freigeschaltet. Melde dich an — dann siehst du die Spiele-Bibliothek.'
                      : 'Gerade sind keine Spiele freigegeben. Sobald die Orga welche freischaltet, erscheinen sie hier.',
            cards: 'games',
        };
    }

    return {
        role: 'assistant',
        text:
            props.chat.servers.length > 0
                ? 'Diese Server laufen gerade:'
                : props.guest
                  ? 'Melde dich an, um laufende Server zu sehen.'
                  : 'Gerade läuft kein Server. Sobald einer startet, steht er hier.',
        cards: 'servers',
    };
}

function send(text: string): void {
    const value = text.trim();

    if (value === '' || agentState.value !== 'idle') {
        return;
    }

    cancelDemo();
    demoDone.value = true;
    pushMessage({ role: 'user', text: value });
    draft.value = '';
    void respond(value);
}

function serversHref(server: HomeChatServer): string {
    // Permanent servers (and everything else without a matching join link) go
    // to the aggregated `/servers` browser; event servers to their event list.
    return server.event_slug
        ? `/servers?event=${encodeURIComponent(server.event_slug)}`
        : '/servers';
}

function serverVariant(status: string): 'live' | 'ok' | 'warn' | 'down' {
    return serverVariants[status] ?? 'warn';
}

function serverLabel(status: string): string {
    return serverLabels[status] ?? status;
}

onMounted(() => {
    if (props.compact) {
        return;
    }

    if (prefersReducedMotion()) {
        finishDemo();

        return;
    }

    startDemo();
});

onBeforeUnmount(() => {
    answerToken++;
    cancelDemo();
});
</script>

<template>
    <section
        class="overflow-hidden rounded-lg border border-border bg-card"
        :class="
            compact
                ? 'flex h-full min-h-0 flex-col rounded-none border-0 bg-transparent'
                : ''
        "
        data-test="home-chat"
        :aria-labelledby="compact ? undefined : 'home-chat-title'"
        :aria-label="compact ? 'LANoMAT Chat' : undefined"
    >
        <div
            v-if="!compact"
            class="flex flex-wrap items-center justify-between gap-3 border-b border-border px-5 py-3 sm:px-6"
        >
            <h2
                id="home-chat-title"
                class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
            >
                LANoMAT // Start
            </h2>
            <LiveIndicator
                v-if="runningCount > 0"
                variant="live"
                :label="runningLabel"
            />
        </div>

        <div
            :class="
                compact
                    ? 'flex min-h-0 flex-1 flex-col px-3 py-3'
                    : 'px-5 py-6 sm:px-8 sm:py-8'
            "
        >
            <!-- The three things a fresh player actually needs, without a detour
                 through the sidebar. -->
            <div
                v-if="!guest && showActions"
                class="mb-4 flex flex-wrap gap-2"
                data-test="home-chat-actions"
            >
                <Button as-child size="sm">
                    <Link href="/games">Spiele installieren</Link>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link href="/servers">Server ansehen</Link>
                </Button>
                <Button as-child variant="outline" size="sm">
                    <Link href="/launcher">Launcher herunterladen</Link>
                </Button>
            </div>

            <!-- The transcript scrolls inside a capped box, so the chips and the
                 input never get pushed below the fold (docs/design.md
                 "Start-page chat"). role=log announces appended answers once. -->
            <ol
                ref="transcript"
                class="max-h-[7rem] space-y-5 overflow-y-auto pr-1 outline-none focus-visible:ring-2 focus-visible:ring-ring sm:max-h-[min(52svh,24rem)]"
                :class="compact ? 'min-h-0 flex-1 sm:max-h-none' : ''"
                role="log"
                aria-live="polite"
                aria-label="Chat-Verlauf"
                tabindex="0"
            >
                <li
                    v-for="(message, index) in messages"
                    :key="message.id"
                    :data-role="message.role"
                >
                    <template v-if="message.role === 'assistant'">
                        <p
                            class="max-w-2xl text-foreground"
                            :class="
                                index === 0
                                    ? compact
                                        ? 'text-lg font-semibold tracking-tight'
                                        : 'text-2xl font-semibold tracking-tight sm:text-3xl'
                                    : 'text-base font-medium'
                            "
                        >
                            {{ message.text
                            }}<span
                                v-if="streamingId === message.id"
                                aria-hidden="true"
                                class="ml-0.5 inline-block h-4 w-0.5 animate-pulse bg-primary align-middle motion-reduce:animate-none"
                            />
                        </p>

                        <div
                            v-if="message.cards"
                            class="mt-4 space-y-5"
                            :data-test="`home-chat-answer-${message.cards}`"
                        >
                            <div
                                v-if="
                                    message.cards === 'games' ||
                                    message.cards === 'live' ||
                                    message.cards === 'both'
                                "
                                data-test="home-chat-games"
                            >
                                <h3
                                    class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                                >
                                    {{
                                        message.cards === 'live'
                                            ? 'Live'
                                            : 'Spiele'
                                    }}
                                </h3>

                                <ul
                                    v-if="
                                        displayGames(message.cards).length > 0
                                    "
                                    class="mt-2 grid gap-3 sm:grid-cols-2"
                                >
                                    <li
                                        v-for="game in displayGames(
                                            message.cards,
                                        )"
                                        :key="game.slug"
                                        class="flex flex-col justify-between rounded-md border border-border bg-background p-4"
                                    >
                                        <div>
                                            <div
                                                class="flex items-start justify-between gap-3"
                                            >
                                                <Link
                                                    :href="`/games/${game.slug}`"
                                                    class="rounded-sm text-lg font-semibold tracking-tight text-foreground outline-none hover:underline focus-visible:ring-2 focus-visible:ring-ring"
                                                >
                                                    {{ game.title }}
                                                </Link>
                                                <LiveIndicator
                                                    v-if="game.lan_ready"
                                                    variant="ok"
                                                    label="LAN"
                                                    :pulse="false"
                                                />
                                            </div>
                                            <p
                                                v-if="game.summary"
                                                class="mt-1 line-clamp-2 text-sm text-muted-foreground"
                                            >
                                                {{ game.summary }}
                                            </p>
                                            <p
                                                v-if="game.server_note"
                                                class="mt-1 font-mono text-[11px] text-muted-foreground"
                                            >
                                                {{ game.server_note }}
                                            </p>
                                            <p
                                                class="mt-2 font-mono text-xs text-muted-foreground tabular-nums"
                                            >
                                                <span v-if="game.version"
                                                    >v{{ game.version }} ·
                                                </span>
                                                {{ game.server_count }} Server
                                            </p>
                                        </div>

                                        <div
                                            class="mt-4 flex flex-wrap items-center gap-x-4 gap-y-2"
                                        >
                                            <template
                                                v-if="
                                                    game.installable &&
                                                    game.launcher_supported
                                                "
                                            >
                                                <Button as-child>
                                                    <a
                                                        :href="
                                                            game.launcher_url ??
                                                            game.install_deeplink
                                                        "
                                                        >Installieren</a
                                                    >
                                                </Button>
                                                <a
                                                    v-if="game.launcher_url"
                                                    :href="
                                                        game.install_deeplink
                                                    "
                                                    class="rounded-sm text-sm text-muted-foreground underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                                    >Im Launcher öffnen</a
                                                >
                                            </template>
                                            <Button
                                                v-else-if="game.play_url"
                                                as-child
                                                variant="outline"
                                            >
                                                <a
                                                    :href="game.play_url"
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    >Spielen</a
                                                >
                                            </Button>
                                            <Link
                                                v-else
                                                :href="`/games/${game.slug}`"
                                                class="rounded-sm text-sm text-muted-foreground underline underline-offset-4 outline-none hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring"
                                                >Ansehen</Link
                                            >
                                        </div>
                                    </li>
                                </ul>

                                <p
                                    v-else
                                    class="mt-2 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                                >
                                    {{
                                        guest
                                            ? 'Noch keine Spiele freigeschaltet — melde dich an.'
                                            : 'Noch keine Spiele freigegeben.'
                                    }}
                                </p>
                            </div>

                            <div
                                v-if="
                                    message.cards === 'servers' ||
                                    message.cards === 'both'
                                "
                                data-test="home-chat-servers"
                            >
                                <h3
                                    class="font-mono text-xs tracking-widest text-muted-foreground uppercase"
                                >
                                    Server
                                </h3>

                                <ul
                                    v-if="chat.servers.length > 0"
                                    class="mt-2 grid gap-3 sm:grid-cols-2"
                                >
                                    <li
                                        v-for="(server, index) in chat.servers"
                                        :key="`${server.title}-${index}`"
                                        class="flex flex-col justify-between rounded-md border border-border bg-background p-4"
                                    >
                                        <div>
                                            <div
                                                class="flex items-start justify-between gap-3"
                                            >
                                                <h4
                                                    class="text-lg font-semibold tracking-tight text-foreground"
                                                >
                                                    {{ server.title }}
                                                </h4>
                                                <LiveIndicator
                                                    :variant="
                                                        serverVariant(
                                                            server.status,
                                                        )
                                                    "
                                                    :label="
                                                        serverLabel(
                                                            server.status,
                                                        )
                                                    "
                                                    :pulse="
                                                        server.status ===
                                                        'provisioning'
                                                    "
                                                />
                                            </div>
                                            <p
                                                class="mt-1 font-mono text-xs text-muted-foreground"
                                            >
                                                <span v-if="server.game">{{
                                                    server.game
                                                }}</span>
                                                <span
                                                    v-if="
                                                        server.game &&
                                                        server.map
                                                    "
                                                >
                                                    ·
                                                </span>
                                                <span v-if="server.map">{{
                                                    server.map
                                                }}</span>
                                                <span
                                                    v-if="
                                                        !server.game &&
                                                        !server.map
                                                    "
                                                    >–</span
                                                >
                                            </p>
                                        </div>

                                        <div
                                            class="mt-3 flex items-end justify-between gap-3"
                                        >
                                            <p
                                                class="font-mono text-2xl leading-none text-foreground tabular-nums"
                                            >
                                                {{ server.players ?? '–'
                                                }}<span
                                                    class="text-sm text-muted-foreground"
                                                    >/{{
                                                        server.max ?? '?'
                                                    }}</span
                                                >
                                            </p>

                                            <Button
                                                v-if="server.join_url"
                                                as-child
                                                size="sm"
                                            >
                                                <a :href="server.join_url"
                                                    >Beitreten</a
                                                >
                                            </Button>
                                            <Button
                                                v-else
                                                as-child
                                                size="sm"
                                                variant="outline"
                                            >
                                                <Link
                                                    :href="serversHref(server)"
                                                    >{{
                                                        server.event_slug
                                                            ? 'Zum Server'
                                                            : 'Alle Server'
                                                    }}</Link
                                                >
                                            </Button>
                                        </div>
                                    </li>
                                </ul>

                                <p
                                    v-else
                                    class="mt-2 rounded-md border border-dashed border-border p-4 text-sm text-muted-foreground"
                                >
                                    {{
                                        guest
                                            ? 'Melde dich an, um laufende Server zu sehen.'
                                            : 'Gerade läuft kein Server.'
                                    }}
                                </p>
                            </div>
                        </div>
                    </template>

                    <p
                        v-else
                        class="ml-auto w-fit max-w-full rounded-md border border-border bg-muted px-4 py-2 font-mono text-sm break-words text-foreground"
                    >
                        {{ message.text }}
                    </p>
                </li>
            </ol>

            <!-- Agent feedback between question and answer. Polite, but the
                 streamed text itself is not re-announced per chunk. -->
            <div
                v-if="agentState !== 'idle'"
                class="mt-4 flex items-center gap-2 text-sm text-muted-foreground"
                role="status"
                aria-live="polite"
                data-test="home-chat-agent-state"
            >
                <span class="flex items-center gap-1" aria-hidden="true">
                    <span
                        class="size-1.5 animate-bounce rounded-full bg-primary [animation-delay:-0.3s] motion-reduce:animate-none"
                    />
                    <span
                        class="size-1.5 animate-bounce rounded-full bg-primary [animation-delay:-0.15s] motion-reduce:animate-none"
                    />
                    <span
                        class="size-1.5 animate-bounce rounded-full bg-primary motion-reduce:animate-none"
                    />
                </span>
                {{
                    agentState === 'thinking'
                        ? 'LANoMAT denkt …'
                        : 'LANoMAT schreibt …'
                }}
            </div>

            <!-- Autoplay typing: visual only, never announced character by character. -->
            <p
                v-if="demoTyping !== null"
                aria-hidden="true"
                class="mt-5"
                data-test="home-chat-typing"
            >
                <span
                    class="inline-flex max-w-full items-center rounded-md border border-border bg-muted px-4 py-2 font-mono text-sm break-words text-foreground"
                    >{{ demoTyping
                    }}<span
                        class="ml-0.5 inline-block h-4 w-0.5 animate-pulse bg-primary align-middle motion-reduce:animate-none"
                /></span>
            </p>

            <!-- The two giant arcade chips. -->
            <div
                class="mt-6 grid gap-3 sm:grid-cols-2"
                data-test="home-chat-suggestions"
            >
                <button
                    v-for="suggestion in chat.suggestions"
                    :key="suggestion"
                    type="button"
                    class="group flex min-h-24 items-center justify-between gap-4 rounded-md border border-border bg-background px-5 py-4 text-left text-lg font-semibold tracking-tight text-foreground transition-colors outline-none hover:border-primary hover:bg-accent focus-visible:ring-2 focus-visible:ring-ring disabled:pointer-events-none disabled:opacity-50 sm:text-xl"
                    :disabled="agentState !== 'idle'"
                    @click="send(suggestion)"
                >
                    <span>{{ suggestion }}</span>
                    <ArrowRight
                        class="size-5 shrink-0 text-muted-foreground transition-colors group-hover:text-primary"
                        aria-hidden="true"
                    />
                </button>
            </div>

            <form
                class="mt-4 flex gap-2"
                :aria-busy="agentState !== 'idle'"
                @submit.prevent="send(draft)"
            >
                <label class="sr-only" for="home-chat-input"
                    >Frage an LANoMAT</label
                >
                <input
                    id="home-chat-input"
                    v-model="draft"
                    type="text"
                    autocomplete="off"
                    placeholder="Frag nach Spielen oder Servern …"
                    class="h-11 min-w-0 flex-1 rounded-md border border-input bg-background px-4 text-base text-foreground outline-none placeholder:text-muted-foreground focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @focus="skipDemo"
                />
                <Button
                    type="submit"
                    size="lg"
                    :disabled="!draft.trim() || agentState !== 'idle'"
                >
                    Fragen
                </Button>
            </form>
        </div>
    </section>
</template>
