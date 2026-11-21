<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Check, Plus } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Skeleton } from '@/components/ui/skeleton';
import { index as lfgIndex } from '@/routes/lfg';
import type { NowPlayingRow, WantToPlayDto, WantToPlayGame } from '@/types/now';
import JoinButton from './JoinButton.vue';
import Panel from './Panel.vue';
import { useNowLabels } from './useNowLabels';

const props = defineProps<{
    data?: WantToPlayDto | null;
    nowPlaying?: NowPlayingRow[] | null;
    eventSlug?: string | null;
    /** Pre-LAN: explain what the signal is for. */
    intro?: boolean;
    loading?: boolean;
}>();

const t = useNowLabels();

const games = ref<WantToPlayGame[]>([]);
watch(
    () => props.data,
    (value) => {
        games.value = (value?.games ?? []).map((g) => ({
            ...g,
            names: [...g.names],
        }));
    },
    { immediate: true, deep: true },
);

function resync() {
    games.value = (props.data?.games ?? []).map((g) => ({
        ...g,
        names: [...g.names],
    }));
    router.reload({ only: ['wantToPlay'] });
}

const adding = ref(false);
const custom = ref('');

const suggestions = computed(() =>
    (props.data?.suggestions ?? []).filter(
        (s) => !games.value.some((g) => g.game === s),
    ),
);
const mine = computed(() => games.value.filter((g) => g.mine));

function toggle(name: string) {
    const url = props.data?.toggleUrl;
    const label = name.trim();

    if (!url || label === '') {
        return;
    }

    // Optimistic: flip locally, the partial reload reconciles with the server.
    const existing = games.value.find((g) => g.game === label);

    if (existing) {
        existing.mine = !existing.mine;
        existing.count += existing.mine ? 1 : -1;
    } else {
        games.value.push({ game: label, count: 1, names: [], mine: true });
    }

    let succeeded = false;

    router.post(
        url,
        { game: label },
        {
            preserveScroll: true,
            preserveState: true,
            only: ['wantToPlay', 'navSignals'],
            // Roll the optimistic flip back to the server state on failure.
            // (onError only covers validation errors; 5xx/network need onFinish.)
            onSuccess: () => {
                succeeded = true;
            },
            onFinish: () => {
                if (!succeeded) {
                    resync();
                }
            },
        },
    );
}

function addGame(name: string) {
    const label = name.trim();

    if (label === '') {
        return;
    }

    if (!games.value.find((g) => g.game === label)?.mine) {
        toggle(label);
    }

    adding.value = false;
    custom.value = '';
}

function who(game: WantToPlayGame): string {
    const more = game.count - 1 - game.names.length;

    if (game.names.length === 0 && more <= 0) {
        return t('only_you');
    }

    const parts = [...game.names];

    if (more > 0) {
        parts.push(t('and_more', { count: more }));
    }

    return t('who_and_you', { names: parts.join(', ') });
}

function joinFor(game: string) {
    return props.nowPlaying?.find((r) => r.game === game)?.join ?? null;
}
</script>

<template>
    <Panel
        :title="t('want_to_play')"
        :link="
            eventSlug
                ? { href: lfgIndex.url(eventSlug), label: t('find_players') }
                : undefined
        "
    >
        <div
            v-if="loading"
            class="flex flex-wrap gap-2 border-t border-border px-4 py-3"
            :aria-label="t('loading')"
            aria-busy="true"
        >
            <Skeleton v-for="n in 4" :key="n" class="h-8 w-28 rounded-full" />
        </div>
        <template v-else>
            <div class="border-t border-border px-4 py-3">
                <p v-if="intro" class="mb-3 text-sm text-muted-foreground">
                    {{ t('want_to_play_intro') }}
                </p>
                <div class="flex flex-wrap gap-2">
                    <button
                        v-for="g in games"
                        :key="g.game"
                        type="button"
                        class="inline-flex min-h-8 items-center gap-2 rounded-full border px-3 text-sm font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:ring-offset-2 focus-visible:ring-offset-background focus-visible:outline-none"
                        :class="
                            g.mine
                                ? 'border-foreground bg-foreground text-background'
                                : 'border-input text-foreground hover:bg-accent'
                        "
                        :aria-pressed="g.mine"
                        @click="toggle(g.game)"
                    >
                        <Check
                            v-if="g.mine"
                            class="size-3.5"
                            aria-hidden="true"
                        />
                        {{ g.game }}
                        <span class="font-mono text-xs tabular-nums">{{
                            g.count
                        }}</span>
                    </button>
                    <button
                        type="button"
                        class="inline-flex min-h-8 items-center gap-1.5 rounded-full border border-dashed border-input px-3 text-sm text-muted-foreground hover:text-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        :aria-expanded="adding"
                        @click="adding = !adding"
                    >
                        <Plus class="size-3.5" aria-hidden="true" />
                        {{ t('add_game') }}
                    </button>
                </div>

                <div v-if="adding" class="mt-3 space-y-2">
                    <div
                        v-if="suggestions.length > 0"
                        class="flex flex-wrap gap-2"
                    >
                        <span class="w-full text-xs text-muted-foreground">{{
                            t('from_library')
                        }}</span>
                        <Button
                            v-for="s in suggestions"
                            :key="s"
                            type="button"
                            size="sm"
                            variant="outline"
                            @click="addGame(s)"
                        >
                            {{ s }}
                        </Button>
                    </div>
                    <form class="flex gap-2" @submit.prevent="addGame(custom)">
                        <Input
                            v-model="custom"
                            :aria-label="t('other_game')"
                            :placeholder="t('other_game')"
                            maxlength="100"
                            class="h-8"
                        />
                        <Button
                            type="submit"
                            size="sm"
                            variant="outline"
                            :disabled="custom.trim() === ''"
                        >
                            {{ t('add') }}
                        </Button>
                    </form>
                </div>
            </div>

            <div
                v-if="!intro"
                class="space-y-2 border-t border-border px-4 py-3"
            >
                <p
                    v-if="mine.length === 0"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('want_to_play_hint') }}
                </p>
                <div
                    v-for="g in mine"
                    :key="g.game"
                    class="flex flex-wrap items-center gap-x-3 gap-y-1 text-sm"
                >
                    <span class="min-w-0 flex-1 basis-48">
                        <span class="font-medium text-foreground">{{
                            g.game
                        }}</span>
                        <span class="text-muted-foreground">
                            · {{ who(g) }}</span
                        >
                    </span>
                    <template v-if="joinFor(g.game)">
                        <span
                            class="font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            {{ joinFor(g.game)?.title }} ·
                            {{ joinFor(g.game)?.players }}/{{
                                joinFor(g.game)?.maxPlayers
                            }}
                        </span>
                        <JoinButton
                            :connect-string="
                                joinFor(g.game)?.connectString ?? ''
                            "
                            :connect-is-link="
                                joinFor(g.game)?.connectIsLink ?? false
                            "
                        />
                    </template>
                </div>
            </div>
        </template>
    </Panel>
</template>
