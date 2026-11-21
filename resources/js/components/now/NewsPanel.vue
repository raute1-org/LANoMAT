<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { formatInstant } from '@/lib/datetime';
import { show as eventShow } from '@/routes/events';
import type { NowNewsItem } from '@/types/now';
import Panel from './Panel.vue';
import { useNowLabels } from './useNowLabels';

defineProps<{ news: NowNewsItem[]; eventSlug?: string | null }>();

const t = useNowLabels();

function date(iso: string | null): string {
    return iso ? formatInstant(iso, { dateStyle: 'medium' }) : '';
}
</script>

<template>
    <Panel :title="t('news')">
        <p
            v-if="news.length === 0"
            class="border-t border-border px-4 py-4 text-sm text-muted-foreground"
        >
            {{ t('news_empty') }}
        </p>
        <ul v-else class="divide-y divide-border border-t border-border">
            <li
                v-for="post in news"
                :key="post.id"
                class="flex flex-wrap items-baseline justify-between gap-x-3 px-4 py-2.5 text-sm"
            >
                <Link
                    v-if="eventSlug"
                    :href="eventShow.url(eventSlug)"
                    class="rounded-sm text-foreground hover:underline focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                >
                    {{ post.title }}
                </Link>
                <span v-else class="text-foreground">{{ post.title }}</span>
                <time
                    v-if="post.publishedAt"
                    class="font-mono text-xs text-muted-foreground tabular-nums"
                    :datetime="post.publishedAt"
                >
                    {{ date(post.publishedAt) }}
                </time>
            </li>
        </ul>
    </Panel>
</template>
