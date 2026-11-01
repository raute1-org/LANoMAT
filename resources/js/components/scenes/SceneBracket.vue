<script setup lang="ts">
import BracketView from '@/components/bracket/BracketView.vue';
import type { BracketMatchDto } from '@/types';

defineProps<{
    config: { headline?: string };
    data: { matches?: BracketMatchDto[] };
    labels: Record<string, string>;
}>();
</script>

<template>
    <div class="flex h-full w-full flex-col gap-6 px-8 py-6">
        <h1 class="text-4xl font-bold tracking-tight text-foreground">
            {{ config.headline ?? labels.bracket_title }}
        </h1>

        <p
            v-if="(data.matches ?? []).length === 0"
            class="text-3xl text-muted-foreground"
        >
            {{ labels.bracket_empty }}
        </p>

        <div v-else class="min-h-0 flex-1">
            <BracketView
                fit
                :page-seconds="10"
                :fit-labels="{
                    hidden: labels.bracket_hidden,
                    page: labels.bracket_page,
                }"
                :matches="data.matches ?? []"
                :my-entry-id="null"
                :match-status-labels="{
                    pending: labels.match_status_pending,
                    ready: labels.match_status_ready,
                    warmup: labels.match_status_warmup,
                    live: labels.match_status_live,
                    reported: labels.match_status_reported,
                    disputed: labels.match_status_disputed,
                    completed: labels.match_status_completed,
                }"
                :report-labels="{
                    report_action: labels.report_action,
                    confirm_action: labels.confirm_action,
                    dispute_action: labels.dispute_action,
                }"
                :bracket-labels="{
                    round: labels.round,
                    winners_bracket: labels.winners_bracket,
                    losers_bracket: labels.losers_bracket,
                    finals: labels.finals,
                }"
                :bracket-state-labels="{
                    bye: labels.bracket_state_bye,
                    not_held: labels.bracket_state_not_held,
                }"
            />
        </div>
    </div>
</template>
