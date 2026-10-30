<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import BracketView from '@/components/bracket/BracketView.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import StandingsTable from '@/components/tournaments/StandingsTable.vue';
import TournamentEntryActions from '@/components/tournaments/TournamentEntryActions.vue';
import { Button } from '@/components/ui/button';
import { formatInstant } from '@/lib/datetime';
import { useTournamentChannel } from '@/composables/useTournamentChannel';
import { index as tournamentsIndex } from '@/routes/tournaments';
import type {
    BracketMatchDto,
    MatchVoiceLinks,
    PendingReportDto,
    TournamentDetail,
    TournamentMyEntry,
    TournamentRegistrationGate,
    TournamentStandingRow,
    TournamentTeamPicker,
} from '@/types';

const props = defineProps<{
    tournament: TournamentDetail;
    matches: BracketMatchDto[];
    /** Round-robin table; null for elimination formats. */
    standings: TournamentStandingRow[] | null;
    standingsLabels: Record<string, string>;
    myEntryId: number | null;
    /** The viewer's pending result claims keyed by match id (participants only). */
    pendingReports: Record<number, PendingReportDto>;
    myEntry: TournamentMyEntry | null;
    registration: TournamentRegistrationGate;
    teamPicker: TournamentTeamPicker | null;
    myMatchVoiceLinks: MatchVoiceLinks;
    /** True for a helper/orga viewer — gates the match card's manual "Go" control. */
    canGoLive: boolean;
    labels: Record<string, string>;
    formatLabels: Record<string, string>;
    statusLabels: Record<string, string>;
    matchStatusLabels: Record<string, string>;
    /** `tournaments.bracket` labels: bye/not_held — BracketMatchCard's never-played-match placeholders. */
    bracketStateLabels: Record<string, string>;
    reportLabels: Record<string, string>;
    warmupLabels: Record<string, string>;
    serverLabels: Record<string, string>;
    serverLinkStatusLabels: Record<string, string>;
    /** `gameservers.live_score` labels (Task 12: CS2 telemetry live score, roadmap 6.9). */
    liveScoreLabels: Record<string, string>;
    /** `voice.join` labels for the multi-provider voice-link cluster. */
    voiceLabels: Record<string, string>;
}>();

const { connection } = useTournamentChannel(props.tournament.id);

// Calm, honest hint only while the live socket is down (the LIVE badge is tournament status, not socket state).
const connectionLost = computed(
    () =>
        connection.value === 'disconnected' ||
        connection.value === 'failed' ||
        connection.value === 'reconnecting',
);

/** Default provider first so the rationed amber action always leads the cluster. */
const voiceLinks = computed(() =>
    [...props.myMatchVoiceLinks].sort(
        (a, b) => Number(b.isDefault) - Number(a.isDefault),
    ),
);

const isLive = computed(() => props.tournament.status === 'live');
const isAnnounced = computed(() => props.tournament.status === 'announced');
const isFinished = computed(() => props.tournament.status === 'finished');
const isNotStarted = computed(
    () =>
        props.tournament.status === 'draft' ||
        props.tournament.status === 'announced' ||
        props.tournament.status === 'enrollment' ||
        props.tournament.status === 'check_in',
);
</script>

<template>
    <Head :title="`${labels.title} — ${tournament.name}`" />

    <PageContainer
        width="wide"
        :back="{
            href: tournamentsIndex.url(tournament.event.slug),
            label: labels.back_to_index,
        }"
    >
        <template #header>
            <div class="flex items-center gap-3">
                <h1
                    class="text-2xl font-semibold tracking-tight text-foreground"
                >
                    {{ tournament.name }}
                </h1>
                <LiveIndicator
                    v-if="isLive"
                    variant="live"
                    :label="statusLabels[tournament.status]"
                />
                <LiveIndicator
                    v-if="isLive && connectionLost"
                    variant="warn"
                    :pulse="false"
                    :label="labels.connection_lost"
                    role="status"
                />
            </div>
            <p class="text-sm text-muted-foreground">
                {{ tournament.event.name }}
            </p>
        </template>

        <div v-if="voiceLinks.length" class="mt-4">
            <h2 class="text-sm font-medium text-muted-foreground">
                {{ voiceLabels.heading }}
            </h2>
            <div class="mt-2 flex flex-wrap items-center gap-3">
                <template v-for="link in voiceLinks" :key="link.provider">
                    <div class="flex items-center gap-2">
                        <Button
                            v-if="link.isDefault"
                            as="a"
                            :href="link.url"
                            variant="default"
                        >
                            {{ labels.join_voice }} — {{ link.label }}
                        </Button>
                        <a
                            v-else
                            :href="link.url"
                            class="rounded-sm text-sm text-muted-foreground underline outline-none focus-visible:ring-2 focus-visible:ring-ring"
                        >
                            {{ link.label }}
                        </a>
                        <!-- M8-infra-later: occupants is 0 until the provider sidecars are wired up (mode A); LiveIndicator only lights once someone is actually in the channel. -->
                        <span
                            :title="voiceLabels.occupants_label"
                            class="inline-flex items-center gap-1"
                        >
                            <LiveIndicator
                                v-if="link.occupants > 0"
                                variant="live"
                                :pulse="false"
                            />
                            <span
                                class="font-mono text-xs text-muted-foreground tabular-nums"
                            >
                                {{ link.occupants }}
                            </span>
                        </span>
                    </div>
                </template>
            </div>
            <p
                v-if="voiceLinks.some((link) => link.isDefault)"
                class="mt-1 text-xs text-muted-foreground"
            >
                {{ voiceLabels.default_hint }}
            </p>
        </div>

        <section
            v-if="isAnnounced"
            class="mt-8 rounded-lg border border-border bg-card p-4"
            :aria-label="labels.announced_info"
        >
            <p class="text-sm font-medium text-foreground" role="status">
                {{ labels.announced_hint }}
            </p>
            <dl class="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
                <div v-if="tournament.gameName">
                    <dt class="text-muted-foreground">{{ labels.game }}</dt>
                    <dd class="font-medium text-foreground">
                        {{ tournament.gameName }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">
                        {{ labels.format }}
                    </dt>
                    <dd class="font-medium text-foreground">
                        {{ formatLabels[tournament.format] }}
                    </dd>
                </div>
                <div v-if="tournament.maxEntries !== null">
                    <dt class="text-muted-foreground">{{ labels.slots }}</dt>
                    <dd
                        class="font-mono font-medium text-foreground tabular-nums"
                    >
                        {{ tournament.maxEntries }}
                    </dd>
                </div>
                <div>
                    <dt class="text-muted-foreground">{{ labels.starts }}</dt>
                    <dd
                        class="font-mono font-medium text-foreground tabular-nums"
                    >
                        {{
                            formatInstant(tournament.startsAt, {
                                dateStyle: 'short',
                                timeStyle: 'short',
                            })
                        }}
                    </dd>
                </div>
            </dl>
            <div v-if="tournament.rules" class="mt-4">
                <h2 class="text-sm font-medium text-muted-foreground">
                    {{ labels.rules }}
                </h2>
                <p
                    class="mt-1 max-w-prose text-sm whitespace-pre-line text-foreground"
                >
                    {{ tournament.rules }}
                </p>
            </div>
        </section>

        <div
            v-else-if="isNotStarted"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.not_started }}
            </p>
            <div class="mt-4 flex justify-center">
                <TournamentEntryActions
                    :tournament-id="tournament.id"
                    :status="tournament.status"
                    :my-entry="myEntry"
                    :registration="registration"
                    :team-picker="teamPicker"
                    :labels="labels"
                />
            </div>
        </div>

        <template v-else>
            <div
                v-if="isFinished"
                class="mt-8 rounded-lg border border-border bg-card p-4"
            >
                <LiveIndicator variant="ok" :label="labels.finished" />
                <p v-if="tournament.winnerName" class="mt-2 text-sm">
                    <span class="text-muted-foreground"
                        >{{ labels.champion }}:</span
                    >
                    <span class="ml-1 font-semibold text-foreground">{{
                        tournament.winnerName
                    }}</span>
                </p>
            </div>

            <div v-if="standings !== null" class="mt-8">
                <StandingsTable
                    :rows="standings"
                    :my-entry-id="myEntryId"
                    :labels="standingsLabels"
                />
            </div>

            <div class="mt-8">
                <BracketView
                    :matches="matches"
                    :my-entry-id="myEntryId"
                    :can-go-live="canGoLive"
                    :pending-reports="pendingReports"
                    :tournament-finished="isFinished"
                    :match-status-labels="matchStatusLabels"
                    :bracket-state-labels="bracketStateLabels"
                    :report-labels="reportLabels"
                    :warmup-labels="warmupLabels"
                    :bracket-labels="labels"
                    :server-labels="serverLabels"
                    :server-link-status-labels="serverLinkStatusLabels"
                    :live-score-labels="liveScoreLabels"
                />
            </div>
        </template>
    </PageContainer>
</template>
