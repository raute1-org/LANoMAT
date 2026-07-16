<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { useEventChannel } from '@/composables/useEventChannel';
import { index as pollsIndex, vote as voteRoute } from '@/routes/polls';
import type { PollResultsDto } from '@/types';

// The broadcast payload (PollUpdated::broadcastWith(), shared with
// PollResults::for()) carries the tallies but not the poll's open/closed
// state — that never changes as a side effect of a vote being cast, so it
// is intentionally omitted from the broadcast and preserved from the
// current prop value when a live update arrives.
type PollBroadcastPayload = Omit<PollResultsDto, 'isOpen'>;

const props = defineProps<{
    event: { id: number; name: string; slug: string };
    poll: PollResultsDto;
    myVoteOptionId: number | null;
    labels: Record<string, string>;
}>();

const poll = ref<PollResultsDto>(props.poll);
const myVoteOptionId = ref<number | null>(props.myVoteOptionId);

useEventChannel<PollBroadcastPayload>(
    props.event.id,
    ['.poll.updated'],
    (payload) => {
        poll.value = { ...payload, isOpen: poll.value.isOpen };
    },
);

function vote(optionId: number): void {
    const form = useForm({ option_id: optionId });

    form.post(voteRoute.url(poll.value.pollId), {
        preserveScroll: true,
        onSuccess: () => {
            myVoteOptionId.value = optionId;
        },
    });
}

function hasVoted(): boolean {
    return myVoteOptionId.value !== null;
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <Link
            :href="pollsIndex.url(event.slug)"
            class="text-sm text-muted-foreground hover:underline"
        >
            &larr; {{ labels.back_to_index }}
        </Link>

        <Card class="mt-4">
            <CardHeader>
                <div class="flex items-center justify-between gap-4">
                    <CardTitle>{{ poll.question }}</CardTitle>
                    <LiveIndicator
                        v-if="poll.isOpen"
                        variant="live"
                        :label="labels.title"
                    />
                    <Badge v-else variant="outline">{{ labels.closed }}</Badge>
                </div>
                <CardDescription>
                    {{ labels.total_votes }}:
                    <span class="font-mono tabular-nums">{{
                        poll.totalVotes
                    }}</span>
                </CardDescription>
            </CardHeader>

            <CardContent class="space-y-4">
                <p v-if="hasVoted()" class="text-sm text-muted-foreground">
                    {{ labels.you_voted }}
                </p>

                <ul class="space-y-3">
                    <li v-for="option in poll.options" :key="option.id">
                        <div class="flex items-center justify-between gap-4">
                            <span class="font-medium">{{ option.label }}</span>
                            <span
                                class="font-mono text-sm text-muted-foreground tabular-nums"
                            >
                                {{ option.count }} ({{ option.percent }}%)
                            </span>
                        </div>

                        <div
                            class="mt-1 h-2 w-full overflow-hidden rounded-full bg-muted"
                        >
                            <div
                                class="h-full rounded-full bg-primary transition-all motion-reduce:transition-none"
                                :style="{ width: `${option.percent}%` }"
                            />
                        </div>

                        <Button
                            class="mt-2"
                            size="sm"
                            :variant="
                                myVoteOptionId === option.id
                                    ? 'default'
                                    : 'outline'
                            "
                            :disabled="!poll.isOpen || hasVoted()"
                            @click="vote(option.id)"
                        >
                            {{ labels.vote }}
                        </Button>
                    </li>
                </ul>
            </CardContent>
        </Card>
    </main>
</template>
