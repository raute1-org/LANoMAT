<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { formatClock } from '@/lib/datetime';
import { formatEuro } from '@/lib/formatEuro';
import type { NowCardDto } from '@/types/now';
import JoinButton from './JoinButton.vue';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{
    title: string;
    cards: NowCardDto[];
    more?: NowCardDto[];
}>();

const t = useNowLabels();
const showMore = ref(false);

interface MatchServer {
    connectString?: string;
    connectIsLink?: boolean;
    status?: string;
}

function matchServer(card: NowCardDto): MatchServer | null {
    const match = card.data.match as { server?: MatchServer } | undefined;

    return match?.server ?? null;
}

// The single amber button of the screen: the first ready match with a server.
const primaryId = computed<string | null>(() => {
    const card = props.cards.find(
        (c) =>
            c.kind === 'match' &&
            c.state === 'ready' &&
            Boolean(matchServer(c)?.connectString) &&
            matchServer(c)?.status === 'ready',
    );

    return card?.id ?? null;
});

const visible = computed(() =>
    showMore.value ? [...props.cards, ...(props.more ?? [])] : props.cards,
);

function time(card: NowCardDto): string | null {
    return card.at ? formatClock(card.at) : null;
}

function dot(card: NowCardDto): 'live' | 'warn' | null {
    if (card.state === 'ready' || card.state === 'live') {
        return 'live';
    }

    return card.state === 'closing_soon' ||
        card.state === 'checkin_open' ||
        card.kind === 'payment'
        ? 'warn'
        : null;
}

function ticketName(card: NowCardDto): string {
    if (typeof card.data.ticketName === 'string') {
        return card.data.ticketName;
    }

    // Fallback for an older payload: the body is "<amount> · <ticket name>".
    return card.body?.split(' · ').slice(1).join(' · ') ?? '';
}

function actionLabel(card: NowCardDto): string {
    return typeof card.data.actionLabel === 'string'
        ? card.data.actionLabel
        : t(`card_action_${card.kind}`);
}
</script>

<template>
    <Panel v-if="cards.length > 0" :title="title">
        <ul class="divide-y divide-border border-t border-border">
            <li
                v-for="card in visible"
                :key="`${card.kind}-${card.id}`"
                class="flex flex-wrap items-center gap-x-3 gap-y-2 px-4 py-3"
            >
                <span class="flex w-2 shrink-0 justify-center">
                    <LiveIndicator
                        v-if="dot(card)"
                        :variant="dot(card) ?? 'live'"
                        :pulse="dot(card) === 'live'"
                    />
                    <span
                        v-else
                        class="size-2 rounded-full bg-muted-foreground/50"
                        aria-hidden="true"
                    />
                </span>
                <span class="min-w-0 flex-1 basis-56 text-sm">
                    <span class="font-semibold text-foreground">{{
                        card.title
                    }}</span>
                    <span
                        v-if="card.kind === 'payment'"
                        class="text-muted-foreground"
                    >
                        ·
                        <span class="font-mono tabular-nums">{{
                            formatEuro(Number(card.data.amountCents))
                        }}</span>
                        <template v-if="ticketName(card)">
                            · {{ ticketName(card) }}
                        </template>
                    </span>
                    <span v-else-if="card.body" class="text-muted-foreground">
                        · {{ card.body }}
                    </span>
                </span>
                <span
                    v-if="card.id === primaryId"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                >
                    {{ matchServer(card)?.connectString }}
                </span>
                <span
                    v-else-if="time(card)"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                >
                    {{ time(card) }}
                </span>
                <span class="flex flex-wrap items-center gap-2">
                    <JoinButton
                        v-if="card.id === primaryId"
                        primary
                        :label="t('connect')"
                        :connect-string="matchServer(card)?.connectString ?? ''"
                        :connect-is-link="
                            matchServer(card)?.connectIsLink ?? false
                        "
                    />
                    <Button
                        v-if="card.href"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="card.href">{{ actionLabel(card) }}</Link>
                    </Button>
                </span>
            </li>
        </ul>
        <div
            v-if="(more?.length ?? 0) > 0"
            class="border-t border-border px-4 py-2"
        >
            <button
                type="button"
                class="rounded-sm text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                :aria-expanded="showMore"
                @click="showMore = !showMore"
            >
                {{
                    showMore
                        ? t('for_you_less')
                        : t('for_you_more', { count: more?.length ?? 0 })
                }}
            </button>
        </div>
    </Panel>
</template>
