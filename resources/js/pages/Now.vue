<script setup lang="ts">
import { Deferred, Head, Link, router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';
import DesktopClientHint from '@/components/DesktopClientHint.vue';
import HomeChat from '@/components/home/HomeChat.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import CountdownLine from '@/components/now/CountdownLine.vue';
import FoodPanel from '@/components/now/FoodPanel.vue';
import ForYouPanel from '@/components/now/ForYouPanel.vue';
import MeStrip from '@/components/now/MeStrip.vue';
import NewsPanel from '@/components/now/NewsPanel.vue';
import NextPanel from '@/components/now/NextPanel.vue';
import NowPlayingPanel from '@/components/now/NowPlayingPanel.vue';
import PermanentShortPanel from '@/components/now/PermanentShortPanel.vue';
import { provideNowLabels } from '@/components/now/useNowLabels';
import WantToPlayPanel from '@/components/now/WantToPlayPanel.vue';
import PageContainer from '@/components/PageContainer.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { useEventChannel } from '@/composables/useEventChannel';
import { useModules } from '@/composables/useModules';
import { dayKey, formatInstant } from '@/lib/datetime';
import {
    index as eventsIndex,
    register as eventsRegister,
    show as eventShow,
} from '@/routes/events';
import type { NowProps } from '@/types/now';

const props = defineProps<NowProps>();

const t = provideNowLabels(() => props.labels);
const { has } = useModules();

const now = ref(Date.now());
let handle: ReturnType<typeof setInterval> | undefined;

function refresh() {
    if (document.visibilityState !== 'visible') {
        return;
    }

    now.value = Date.now();
    router.reload({
        only: ['event', 'forYou', 'nowPlaying', 'food', 'schedule'],
    });
}

onMounted(() => {
    if (props.state === 'live') {
        handle = setInterval(refresh, 30_000);
    }
});
onUnmounted(() => {
    if (handle) {
        clearInterval(handle);
    }
});

// Presence changes: refresh just the two blocks that depend on it.
if (props.event) {
    useEventChannel(props.event.id, ['.presence.updated'], () => {
        // nowPlaying is only computed (and shown) while the event is live.
        router.reload({
            only:
                props.state === 'live' ? ['nowPlaying', 'forYou'] : ['forYou'],
        });
    });
}

const liveMeta = computed(() => {
    const event = props.event;

    if (!event) {
        return '';
    }

    const clock = formatInstant(new Date(now.value), {
        hour: '2-digit',
        minute: '2-digit',
    });
    const parts: string[] = [];

    if (event.startsAt) {
        const days = Math.round(
            (Date.parse(dayKey(new Date(now.value))) -
                Date.parse(dayKey(event.startsAt))) /
                86_400_000,
        );
        parts.push(t('day_n', { n: Math.max(1, days + 1) }));
    }

    parts.push(clock);
    parts.push(
        t('present_of', {
            checked: event.checkedInCount,
            total: event.registeredCount,
        }),
    );

    return parts.join(' · ');
});

const dateRange = computed(() => {
    const event = props.event;

    if (!event?.startsAt) {
        return '';
    }

    const opts: Intl.DateTimeFormatOptions = {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    };
    const range = event.endsAt
        ? t('date_range', {
              from: formatInstant(event.startsAt, opts),
              to: formatInstant(event.endsAt, opts),
          })
        : formatInstant(event.startsAt, opts);

    return event.location ? `${range} · ${event.location}` : range;
});
</script>

<template>
    <Head :title="t('title')" />

    <PageContainer width="wide">
        <div class="space-y-4">
            <!-- desktop app hint: only with a matching build, dismissible per version -->
            <DesktopClientHint :labels="clientHint" />

            <!-- Header -->
            <div
                class="flex flex-wrap items-baseline justify-between gap-x-6 gap-y-2"
            >
                <template v-if="event">
                    <div class="flex flex-wrap items-baseline gap-x-4 gap-y-1">
                        <h1
                            class="text-2xl font-semibold tracking-tight text-foreground"
                        >
                            {{ event.name }}
                        </h1>
                        <span
                            v-if="state === 'live'"
                            class="inline-flex items-center gap-2 font-mono text-xs text-muted-foreground tabular-nums"
                        >
                            <LiveIndicator :label="t('live')" />
                            {{ liveMeta }}
                        </span>
                        <span v-else class="text-sm text-muted-foreground">{{
                            dateRange
                        }}</span>
                    </div>
                    <CountdownLine
                        v-if="state === 'upcoming' && event.startsAt"
                        :starts-at="event.startsAt"
                    />
                </template>
                <div v-else>
                    <h1
                        class="text-2xl font-semibold tracking-tight text-foreground"
                    >
                        {{ t('none_title') }}
                    </h1>
                    <p class="mt-1 text-sm text-muted-foreground">
                        {{ t('none_body') }}
                    </p>
                </div>
            </div>

            <!-- The three shortcuts, visible without opening the chat. -->
            <div class="flex flex-wrap gap-2" data-test="now-actions">
                <Button v-if="has('game_recipes')" as-child size="lg">
                    <Link href="/games">Spiele installieren</Link>
                </Button>
                <Button
                    v-if="has('game_servers')"
                    as-child
                    variant="outline"
                    size="lg"
                >
                    <Link href="/servers">Server ansehen</Link>
                </Button>
                <Button
                    v-if="has('game_recipes')"
                    as-child
                    variant="outline"
                    size="lg"
                >
                    <Link href="/app">App herunterladen</Link>
                </Button>
            </div>

            <!-- The start-page chat: the front door after login. The action
                 row already sits above it on this page. -->
            <HomeChat :chat="chat" :show-actions="false" />

            <!-- Live -->
            <template v-if="state === 'live' && event">
                <ForYouPanel
                    :title="t('for_you')"
                    :cards="forYou.cards"
                    :more="forYou.more"
                />
                <div class="flex flex-wrap gap-4">
                    <div
                        v-if="nowPlaying !== null"
                        class="min-w-[min(100%,22rem)] flex-[1.4]"
                    >
                        <Deferred data="nowPlaying">
                            <template #fallback>
                                <NowPlayingPanel loading />
                            </template>
                            <template #rescue>
                                <NowPlayingPanel failed />
                            </template>
                            <NowPlayingPanel :rows="nowPlaying" />
                        </Deferred>
                    </div>
                    <div
                        v-if="wantToPlay !== null"
                        class="min-w-[min(100%,18rem)] flex-1"
                    >
                        <Deferred data="wantToPlay">
                            <template #fallback>
                                <WantToPlayPanel loading />
                            </template>
                            <template #rescue>
                                <WantToPlayPanel failed />
                            </template>
                            <WantToPlayPanel
                                :data="wantToPlay"
                                :now-playing="nowPlaying"
                                :event-slug="event.slug"
                            />
                        </Deferred>
                    </div>
                </div>
                <div v-if="food || schedule" class="flex flex-wrap gap-4">
                    <div v-if="food" class="min-w-[min(100%,20rem)] flex-1">
                        <FoodPanel
                            :food="food"
                            :event-slug="event.slug"
                            :registration-open="event.availability === 'open'"
                        />
                    </div>
                    <div v-if="schedule" class="min-w-[min(100%,20rem)] flex-1">
                        <NextPanel
                            :schedule="schedule"
                            :event-slug="event.slug"
                        />
                    </div>
                </div>
                <MeStrip
                    v-if="me"
                    :me="me"
                    :event-slug="event.slug"
                    :news-count="news.length"
                />
            </template>

            <!-- Upcoming -->
            <template v-else-if="state === 'upcoming' && event">
                <div
                    v-if="event.availability === 'open' && !me?.registration"
                    class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 rounded-md border border-border bg-card px-4 py-3"
                >
                    <p class="text-sm text-foreground">
                        {{ t('register_open') }}
                        <span
                            v-if="event.freeSpots !== null"
                            class="text-muted-foreground"
                        >
                            {{
                                t('register_spots', { count: event.freeSpots })
                            }}
                        </span>
                    </p>
                    <Button as-child size="sm">
                        <Link :href="eventsRegister.url(event.slug)">{{
                            t('register_cta')
                        }}</Link>
                    </Button>
                </div>
                <ForYouPanel
                    :title="t('for_you_pre')"
                    :cards="forYou.cards"
                    :more="forYou.more"
                />
                <div class="flex flex-wrap gap-4">
                    <div
                        v-if="wantToPlay !== null"
                        class="min-w-[min(100%,22rem)] flex-[1.4]"
                    >
                        <Deferred data="wantToPlay">
                            <template #fallback>
                                <WantToPlayPanel loading />
                            </template>
                            <template #rescue>
                                <WantToPlayPanel failed />
                            </template>
                            <WantToPlayPanel
                                intro
                                :data="wantToPlay"
                                :event-slug="event.slug"
                            />
                        </Deferred>
                    </div>
                    <div class="min-w-[min(100%,18rem)] flex-1">
                        <Panel
                            :title="t('who_comes')"
                            :link="{
                                href: eventShow.url(event.slug),
                                label: t('event_info'),
                            }"
                        >
                            <div
                                class="flex items-baseline gap-2 border-t border-border px-4 py-3 text-sm"
                            >
                                <span
                                    class="font-mono text-2xl font-semibold text-foreground tabular-nums"
                                    >{{ event.registeredCount }}</span
                                >
                                <span class="text-muted-foreground">{{
                                    event.freeSpots !== null
                                        ? t('who_comes_of', {
                                              total:
                                                  event.registeredCount +
                                                  event.freeSpots,
                                          })
                                        : t('who_comes_open')
                                }}</span>
                            </div>
                        </Panel>
                    </div>
                </div>
                <div class="flex flex-wrap gap-4">
                    <div
                        v-if="has('news')"
                        class="min-w-[min(100%,20rem)] flex-1"
                    >
                        <NewsPanel :news="news" :event-slug="event.slug" />
                    </div>
                    <div
                        v-if="permanent !== null"
                        class="min-w-[min(100%,20rem)] flex-1"
                    >
                        <PermanentShortPanel
                            :title="t('permanent_open')"
                            :servers="permanent"
                        />
                    </div>
                </div>
            </template>

            <!-- None -->
            <template v-else>
                <div class="flex flex-wrap gap-4">
                    <div
                        v-if="permanent !== null"
                        class="min-w-[min(100%,22rem)] flex-1"
                    >
                        <PermanentShortPanel
                            :title="t('permanent')"
                            :servers="permanent"
                        />
                    </div>
                    <div class="min-w-[min(100%,18rem)] flex-1">
                        <Panel
                            :title="t('next')"
                            :link="{
                                href: eventsIndex.url(),
                                label: t('all_events'),
                            }"
                        >
                            <p
                                class="border-t border-border px-4 py-3 text-sm text-muted-foreground"
                            >
                                {{ t('next_event_hint') }}
                            </p>
                        </Panel>
                    </div>
                </div>
                <NewsPanel v-if="has('news')" :news="news" />
            </template>
        </div>
    </PageContainer>
</template>
