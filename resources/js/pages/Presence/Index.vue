<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import Panel from '@/components/now/Panel.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Skeleton } from '@/components/ui/skeleton';
import UserAvatar from '@/components/UserAvatar.vue';
import { useEventChannel } from '@/composables/useEventChannel';
import { usePageLoadState } from '@/composables/usePageLoadState';
import { useResync } from '@/composables/useResync';
import { show as tournamentsShow } from '@/routes/tournaments';
import type { PresenceBoardDto } from '@/types';

interface PresenceLabels {
    title: string;
    checked_in_count: string;
    error: string;
    participants: {
        heading: string;
        empty: string;
        idle: string;
        seat_label: string;
        stream_link: string;
    };
    free_slots: {
        heading: string;
        empty: string;
        open_unlimited: string;
        open_spots: string;
        join: string;
    };
    live_matches: {
        heading: string;
        empty: string;
        live_label: string;
    };
    filters: {
        free_slots_only: string;
        playing_only: string;
        streams_only: string;
        friends_only: string;
    };
}

const props = defineProps<{
    event: { id: number; name: string; slug: string };
    presence: PresenceBoardDto;
    labels: PresenceLabels;
}>();

// Live board: check-ins and match/tournament transitions push a bare
// `presence.updated` signal on the public `event.{id}` channel; reload just
// the `presence` prop rather than poll — mirrors Screen/Show.vue's
// `.scenes.updated` handling.
useEventChannel(props.event.id, ['.presence.updated'], () => {
    router.reload({ only: ['presence'] });
});

// Signals missed while offline are recovered on reconnect / tab focus.
useResync(() => router.reload({ only: ['presence'] }));

function t<S extends keyof PresenceLabels>(
    section: S,
    key: keyof PresenceLabels[S],
): string {
    const group = props.labels[section];

    return String(group[key] ?? key);
}

function checkedInCountLabel(): string {
    return props.labels.checked_in_count.replace(
        ':count',
        String(props.presence.checkedInCount),
    );
}

// Inertia renders this page synchronously on first load, so "loading"/"error"
// only apply to a subsequent client-driven navigation/refresh (mirrors
// Servers/Index.vue) rather than invented polling.
const { isNavigating, hasError } = usePageLoadState();

// Quiet client-side filter toggles — no server round-trip, the board is
// already fully loaded.
const freeSlotsOnly = ref(false);
const playingOnly = ref(false);
const streamsOnly = ref(false);
const friendsOnly = ref(false);

const visibleParticipants = computed(() => {
    let participants = props.presence.participants;

    if (playingOnly.value) {
        participants = participants.filter((p) => p.isPlaying);
    }

    if (streamsOnly.value) {
        participants = participants.filter((p) => p.streamUrl !== null);
    }

    if (friendsOnly.value) {
        participants = participants.filter((p) => p.isFriend);
    }

    return participants;
});

function openSpotsLabel(openSpots: number | null): string {
    return openSpots === null
        ? t('free_slots', 'open_unlimited')
        : props.labels.free_slots.open_spots.replace(
              ':count',
              String(openSpots),
          );
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
        <template #actions>
            <p class="font-mono text-sm text-muted-foreground tabular-nums">
                {{ checkedInCountLabel() }}
            </p>
        </template>

        <!-- loading: only shown mid-navigation, e.g. a partial reload -->
        <div v-if="isNavigating" class="mt-6 space-y-4">
            <Skeleton class="h-24 w-full rounded-lg" />
            <Skeleton class="h-24 w-full rounded-lg" />
        </div>

        <!-- error: a failed client-driven reload/navigation -->
        <div
            v-else-if="hasError"
            class="mt-6 rounded-md border border-destructive/50 bg-destructive/10 p-6 text-center"
        >
            <p class="text-sm text-destructive">{{ labels.error }}</p>
        </div>

        <template v-else>
            <!-- filters -->
            <div class="mt-6 flex flex-wrap gap-2">
                <Button
                    type="button"
                    size="sm"
                    :variant="freeSlotsOnly ? 'default' : 'outline'"
                    :aria-pressed="freeSlotsOnly"
                    @click="freeSlotsOnly = !freeSlotsOnly"
                >
                    {{ labels.filters.free_slots_only }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="playingOnly ? 'default' : 'outline'"
                    :aria-pressed="playingOnly"
                    @click="playingOnly = !playingOnly"
                >
                    {{ labels.filters.playing_only }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="streamsOnly ? 'default' : 'outline'"
                    :aria-pressed="streamsOnly"
                    @click="streamsOnly = !streamsOnly"
                >
                    {{ labels.filters.streams_only }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="friendsOnly ? 'default' : 'outline'"
                    :aria-pressed="friendsOnly"
                    @click="friendsOnly = !friendsOnly"
                >
                    {{ labels.filters.friends_only }}
                </Button>
            </div>

            <div class="mt-4 space-y-4">
                <!-- participants -->
                <Panel :title="t('participants', 'heading')">
                    <p
                        v-if="visibleParticipants.length === 0"
                        class="border-t border-border p-4 text-center text-sm text-muted-foreground"
                    >
                        {{ t('participants', 'empty') }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                    >
                        <li
                            v-for="participant in visibleParticipants"
                            :key="participant.registrationId"
                            class="flex items-center gap-3 px-4 py-2"
                        >
                            <UserAvatar
                                :name="participant.name"
                                :src="participant.avatarUrl"
                                :size="32"
                            />

                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2">
                                    <p
                                        class="truncate font-medium text-foreground"
                                    >
                                        {{ participant.name }}
                                    </p>
                                    <LiveIndicator
                                        v-if="participant.isPlaying"
                                        variant="live"
                                        :label="t('live_matches', 'live_label')"
                                    />
                                </div>
                                <p
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{
                                        participant.activity ??
                                        t('participants', 'idle')
                                    }}
                                </p>
                            </div>

                            <a
                                v-if="participant.streamUrl"
                                :href="participant.streamUrl"
                                target="_blank"
                                rel="noopener"
                                class="shrink-0 rounded-md border border-border px-2 py-1 text-xs font-medium text-muted-foreground underline-offset-4 hover:text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                            >
                                {{ t('participants', 'stream_link') }}
                            </a>

                            <div
                                v-if="participant.seatLabel"
                                class="shrink-0 text-right"
                            >
                                <p class="text-xs text-muted-foreground">
                                    {{ t('participants', 'seat_label') }}
                                </p>
                                <p
                                    class="font-mono text-sm text-foreground tabular-nums"
                                >
                                    {{ participant.seatLabel }}
                                </p>
                            </div>
                        </li>
                    </ul>
                </Panel>

                <!-- free slots -->
                <Panel v-if="!playingOnly" :title="t('free_slots', 'heading')">
                    <p
                        v-if="presence.freeSlots.length === 0"
                        class="border-t border-border p-4 text-center text-sm text-muted-foreground"
                    >
                        {{ t('free_slots', 'empty') }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                    >
                        <li
                            v-for="slot in presence.freeSlots"
                            :key="slot.tournamentId"
                            class="flex items-center justify-between gap-3 px-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ slot.name }}
                                </p>
                                <p
                                    v-if="slot.game"
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    {{ slot.game }}
                                </p>
                            </div>
                            <div class="flex shrink-0 items-center gap-3">
                                <span
                                    class="font-mono text-sm text-foreground tabular-nums"
                                    >{{ openSpotsLabel(slot.openSpots) }}</span
                                >
                                <Button as-child size="sm" variant="outline">
                                    <Link
                                        :href="
                                            tournamentsShow.url(
                                                slot.tournamentId,
                                            )
                                        "
                                        >{{ t('free_slots', 'join') }}</Link
                                    >
                                </Button>
                            </div>
                        </li>
                    </ul>
                </Panel>

                <!-- live matches -->
                <Panel
                    v-if="!freeSlotsOnly"
                    :title="t('live_matches', 'heading')"
                >
                    <p
                        v-if="presence.liveMatches.length === 0"
                        class="border-t border-border p-4 text-center text-sm text-muted-foreground"
                    >
                        {{ t('live_matches', 'empty') }}
                    </p>

                    <ul
                        v-else
                        class="divide-y divide-border border-t border-border"
                    >
                        <li
                            v-for="match in presence.liveMatches"
                            :key="match.matchId"
                            class="flex items-center justify-between gap-3 px-4 py-2.5"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ match.label }}
                                </p>
                                <p
                                    class="truncate text-sm text-muted-foreground"
                                >
                                    <span v-if="match.game"
                                        >{{ match.game }} · </span
                                    >{{ match.players.join(' vs ') }}
                                </p>
                            </div>
                            <LiveIndicator
                                variant="live"
                                :label="t('live_matches', 'live_label')"
                            />
                        </li>
                    </ul>
                </Panel>
            </div>
        </template>
    </PageContainer>
</template>
