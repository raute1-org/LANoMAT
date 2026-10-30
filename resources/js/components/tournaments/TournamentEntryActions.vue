<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import {
    checkin as checkinTournament,
    enroll as enrollTournament,
    withdraw as withdrawTournament,
} from '@/routes/tournaments';
import type {
    TournamentMyEntry,
    TournamentRegistrationGate,
    TournamentTeamPicker,
} from '@/types';

const props = defineProps<{
    tournamentId: number;
    status: 'draft' | 'enrollment' | 'check_in' | 'live' | 'finished';
    myEntry: TournamentMyEntry | null;
    registration: TournamentRegistrationGate;
    teamPicker?: TournamentTeamPicker | null;
    labels: Record<string, string>;
}>();

const form = useForm<{ team_id: number | null }>({ team_id: null });

const selectedTeam = ref<string>('');

const readyTeams = computed(
    () => props.teamPicker?.teams.filter((team) => team.eligible) ?? [],
);

/** What the viewer can do right now; one place so Index and Show never drift. */
const mode = computed<
    'enroll' | 'checkin' | 'entered' | 'register_first' | 'none'
>(() => {
    const open = props.status === 'enrollment' || props.status === 'check_in';

    if (!open) {
        return 'none';
    }

    if (props.registration.required) {
        return props.myEntry !== null || props.status === 'enrollment'
            ? 'register_first'
            : 'none';
    }

    if (props.myEntry === null) {
        return props.status === 'enrollment' ? 'enroll' : 'none';
    }

    return props.status === 'check_in' && props.myEntry.status !== 'checked_in'
        ? 'checkin'
        : 'entered';
});

function enroll() {
    // Solo tournaments post no team; team tournaments post the picked team.
    form.transform((data) => ({
        ...data,
        team_id: props.teamPicker ? Number(selectedTeam.value) : null,
    }));
    form.post(enrollTournament.url(props.tournamentId), {
        preserveScroll: true,
    });
}

function checkin() {
    form.post(checkinTournament.url(props.tournamentId), {
        preserveScroll: true,
    });
}

function withdraw() {
    if (!confirm(props.labels.withdraw_confirm)) {
        return;
    }

    form.post(withdrawTournament.url(props.tournamentId), {
        preserveScroll: true,
    });
}
</script>

<template>
    <div v-if="mode !== 'none'" class="flex flex-col items-end gap-1.5">
        <div class="flex flex-wrap items-center justify-end gap-2">
            <Badge
                v-if="myEntry && mode !== 'register_first'"
                variant="outline"
            >
                {{
                    myEntry.status === 'checked_in'
                        ? labels.checked_in
                        : labels.entered
                }}
            </Badge>
            <Select
                v-if="
                    mode === 'enroll' &&
                    teamPicker &&
                    teamPicker.teams.length > 0
                "
                v-model="selectedTeam"
            >
                <SelectTrigger
                    class="h-8 w-48"
                    :aria-label="labels.team_pick_label"
                >
                    <SelectValue :placeholder="labels.team_pick_placeholder" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="team in teamPicker.teams"
                        :key="team.id"
                        :value="String(team.id)"
                        :disabled="!team.eligible"
                    >
                        <span
                            v-if="team.tag"
                            class="font-mono text-xs text-muted-foreground"
                            >[{{ team.tag }}]</span
                        >
                        {{ team.name }}
                        <span
                            v-if="!team.eligible"
                            class="text-xs text-muted-foreground"
                        >
                            ({{
                                labels.team_pick_incomplete
                                    .replace(':count', String(team.memberCount))
                                    .replace(
                                        ':size',
                                        String(teamPicker.teamSize),
                                    )
                            }})
                        </span>
                    </SelectItem>
                </SelectContent>
            </Select>
            <Button
                v-if="
                    mode === 'enroll' &&
                    (!teamPicker || teamPicker.teams.length > 0)
                "
                size="sm"
                :disabled="
                    form.processing || (!!teamPicker && selectedTeam === '')
                "
                @click="enroll"
            >
                {{ teamPicker ? labels.enroll_team : labels.enroll }}
            </Button>
            <Button
                v-else-if="mode === 'enroll' && teamPicker"
                as-child
                size="sm"
                variant="outline"
            >
                <Link :href="teamPicker.createUrl">{{
                    labels.team_create
                }}</Link>
            </Button>
            <Button
                v-else-if="mode === 'checkin'"
                size="sm"
                variant="outline"
                :disabled="form.processing"
                @click="checkin"
            >
                {{ labels.check_in }}
            </Button>
            <Button
                v-if="myEntry?.canWithdraw && mode !== 'register_first'"
                size="sm"
                variant="ghost"
                :disabled="form.processing"
                @click="withdraw"
            >
                {{ labels.withdraw }}
            </Button>
            <Button
                v-if="mode === 'register_first' && registration.open"
                as-child
                size="sm"
            >
                <Link :href="registration.url">{{ labels.register_now }}</Link>
            </Button>
        </div>
        <p
            v-if="mode === 'enroll' && teamPicker"
            class="max-w-56 text-right text-xs text-muted-foreground"
        >
            {{
                teamPicker.teams.length === 0
                    ? labels.team_no_team.replace(
                          ':size',
                          String(teamPicker.teamSize),
                      )
                    : readyTeams.length === 0
                      ? labels.team_none_ready.replace(
                            ':size',
                            String(teamPicker.teamSize),
                        )
                      : ''
            }}
        </p>
        <p
            v-if="mode === 'register_first'"
            class="max-w-56 text-right text-xs text-muted-foreground"
        >
            {{
                registration.open
                    ? labels.registration_required
                    : labels.registration_not_open
            }}
        </p>
    </div>
</template>
