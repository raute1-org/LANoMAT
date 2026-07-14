<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    checkin as checkinTournament,
    enroll as enrollTournament,
    show as showTournament,
} from '@/routes/tournaments';
import type { TournamentSummary } from '@/types';

defineProps<{
    event: { name: string; slug: string };
    tournaments: TournamentSummary[];
    labels: Record<string, string>;
    statusLabels: Record<string, string>;
    formatLabels: Record<string, string>;
}>();

function enroll(tournament: TournamentSummary) {
    useForm({}).post(enrollTournament.url(tournament.id), {
        preserveScroll: true,
    });
}

function checkin(tournament: TournamentSummary) {
    useForm({}).post(checkinTournament.url(tournament.id), {
        preserveScroll: true,
    });
}

function ctaFor(tournament: TournamentSummary): 'enroll' | 'checkin' | null {
    if (tournament.status === 'enrollment') {
        return 'enroll';
    }

    if (tournament.status === 'check_in') {
        return 'checkin';
    }

    return null;
}

function formatDate(iso: string | null): string {
    return iso ? new Date(iso).toLocaleString() : '';
}
</script>

<template>
    <Head :title="labels.title" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <p
            v-if="tournaments.length === 0"
            class="mt-6 text-sm text-muted-foreground"
        >
            {{ labels.no_tournaments }}
        </p>

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
                            · {{ formatDate(tournament.startsAt) }}
                        </template>
                    </p>
                </div>

                <div class="flex shrink-0 items-center gap-2">
                    <Badge variant="outline">{{
                        statusLabels[tournament.status]
                    }}</Badge>
                    <Button
                        v-if="ctaFor(tournament) === 'enroll'"
                        size="sm"
                        @click="enroll(tournament)"
                    >
                        {{ labels.enroll }}
                    </Button>
                    <Button
                        v-else-if="ctaFor(tournament) === 'checkin'"
                        size="sm"
                        variant="outline"
                        @click="checkin(tournament)"
                    >
                        {{ labels.check_in }}
                    </Button>
                </div>
            </li>
        </ul>
    </main>
</template>
