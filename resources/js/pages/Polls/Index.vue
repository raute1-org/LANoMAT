<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import LiveIndicator from '@/components/LiveIndicator.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Badge } from '@/components/ui/badge';
import { show as showPoll } from '@/routes/polls';
import type { PollSummaryDto } from '@/types';

defineProps<{
    event: { name: string; slug: string };
    polls: PollSummaryDto[];
    labels: Record<string, string>;
}>();

function isOpen(poll: PollSummaryDto): boolean {
    return poll.status === 'open';
}
</script>

<template>
    <Head :title="`${labels.index_title} — ${event.name}`" />

    <PageContainer
        width="default"
        :title="`${labels.index_title} — ${event.name}`"
    >
        <div
            v-if="polls.length === 0"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.no_polls }}
            </p>
        </div>

        <ul
            v-else
            class="mt-8 divide-y divide-border rounded-lg border border-border"
        >
            <li
                v-for="poll in polls"
                :key="poll.id"
                class="flex items-center justify-between gap-4 px-4 py-4"
            >
                <div>
                    <Link
                        :href="showPoll.url(poll.id)"
                        class="font-medium hover:underline"
                    >
                        {{ poll.question }}
                    </Link>
                    <p class="text-sm text-muted-foreground">
                        {{ labels.total_votes }}:
                        <span class="font-mono tabular-nums">{{
                            poll.totalVotes
                        }}</span>
                    </p>
                </div>

                <LiveIndicator
                    v-if="isOpen(poll)"
                    variant="live"
                    :label="poll.statusLabel"
                />
                <Badge v-else variant="outline">{{ poll.statusLabel }}</Badge>
            </li>
        </ul>
    </PageContainer>
</template>
