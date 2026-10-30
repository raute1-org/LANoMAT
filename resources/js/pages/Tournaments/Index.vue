<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import TournamentEntryActions from '@/components/tournaments/TournamentEntryActions.vue';
import { Badge } from '@/components/ui/badge';
import { formatInstant } from '@/lib/datetime';
import { show as showTournament } from '@/routes/tournaments';
import type { TournamentRegistrationGate, TournamentSummary } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    tournaments: TournamentSummary[];
    registration: TournamentRegistrationGate;
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
    formatLabels: Record<string, string>;
    ownershipHintLabels: Record<string, string>;
}>();

function formatDate(iso: string | null): string {
    return iso
        ? formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' })
        : '';
}

function entryCountLabel(tournament: TournamentSummary): string {
    const count =
        tournament.maxEntries === null
            ? String(tournament.entryCount)
            : `${tournament.entryCount} / ${tournament.maxEntries}`;
    const unit =
        tournament.teamSize > 1
            ? props.labels.entries_teams
            : props.labels.entries_players;

    return `${count} ${unit}`;
}

function isFull(tournament: TournamentSummary): boolean {
    return (
        tournament.maxEntries !== null &&
        tournament.entryCount >= tournament.maxEntries
    );
}

function isLive(tournament: TournamentSummary): boolean {
    return tournament.status === 'live';
}

/**
 * Advisory-only warning (M9 task 9.7): shown ONLY for a confirmed
 * `not_owned` result, right where the enroll CTA lives, so it's visible
 * without being alarming. `unknown` (by far the common case — most games
 * have no provider mapping) renders nothing, to avoid warning noise; this
 * never disables `enroll()` above regardless of the hint.
 */
function showsOwnershipWarning(tournament: TournamentSummary): boolean {
    return (
        tournament.status === 'enrollment' &&
        tournament.myEntry === null &&
        !props.registration.required &&
        tournament.ownershipHint === 'not_owned'
    );
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
        <div
            v-if="tournaments.length === 0"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.no_tournaments }}
            </p>
        </div>

        <ul
            v-else
            class="mt-8 divide-y divide-border rounded-lg border border-border"
        >
            <li
                v-for="tournament in tournaments"
                :key="tournament.id"
                class="flex items-center justify-between gap-4 px-4 py-4"
            >
                <div>
                    <Link
                        :href="showTournament.url(tournament.id)"
                        class="font-medium hover:underline"
                    >
                        {{ tournament.name }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                        {{ formatLabels[tournament.format] }} ·
                        {{ statusLabels[tournament.status] }}
                        <template v-if="tournament.startsAt">
                            ·
                            <span class="font-mono tabular-nums">{{
                                formatDate(tournament.startsAt)
                            }}</span>
                        </template>
                    </p>
                    <p class="text-sm text-muted-foreground">
                        <span class="font-mono tabular-nums">{{
                            entryCountLabel(tournament)
                        }}</span>
                        <template
                            v-if="
                                isFull(tournament) &&
                                tournament.status === 'enrollment'
                            "
                        >
                            · {{ labels.full }}
                        </template>
                    </p>
                </div>

                <div class="flex shrink-0 flex-col items-end gap-1.5">
                    <div class="flex items-center gap-2">
                        <LiveIndicator
                            v-if="isLive(tournament)"
                            variant="live"
                            :label="statusLabels[tournament.status]"
                        />
                        <Badge v-else variant="outline">{{
                            statusLabels[tournament.status]
                        }}</Badge>
                    </div>
                    <TournamentEntryActions
                        :tournament-id="tournament.id"
                        :status="tournament.status"
                        :my-entry="tournament.myEntry"
                        :registration="registration"
                        :team-picker="tournament.teamPicker"
                        :labels="labels"
                    />
                    <!-- Advisory only — never blocks enroll() above. See GameOwnershipHint. -->
                    <p
                        v-if="showsOwnershipWarning(tournament)"
                        class="max-w-56 text-right text-xs text-muted-foreground"
                    >
                        {{
                            ownershipHintLabels.not_owned_warning.replace(
                                ':game',
                                tournament.gameName ?? tournament.name,
                            )
                        }}
                    </p>
                </div>
            </li>
        </ul>
    </PageContainer>
</template>
