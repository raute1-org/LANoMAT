<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import LiveIndicator from '@/components/LiveIndicator.vue';
import { Button } from '@/components/ui/button';
import { formatInstant } from '@/lib/datetime';
import { show as cateringShow } from '@/routes/catering';
import type { FoodNowDto } from '@/types/now';
import Panel from './Panel.vue';
import { useNowLabels } from './useNowLabels';

defineProps<{ food: FoodNowDto; eventSlug: string }>();

const t = useNowLabels();

function hm(iso: string): string {
    return formatInstant(iso, { hour: '2-digit', minute: '2-digit' });
}
</script>

<template>
    <Panel
        :title="t('food')"
        :link="{ href: cateringShow.url(eventSlug), label: t('all_rounds') }"
    >
        <div class="space-y-2 border-t border-border px-4 py-3 text-sm">
            <template v-if="food.open">
                <div class="flex flex-wrap items-baseline gap-x-3">
                    <span class="font-semibold text-foreground">{{
                        food.open.title
                    }}</span>
                    <span
                        v-if="food.open.closesAt"
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{ t('closes_at', { time: hm(food.open.closesAt) }) }}
                    </span>
                    <span
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{
                            t('ordered_count', { count: food.open.orderCount })
                        }}
                    </span>
                </div>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <span class="text-muted-foreground">
                        {{
                            food.open.mine
                                ? t('your_order', { order: food.open.mine })
                                : t('nothing_ordered')
                        }}
                    </span>
                    <Button as-child size="sm" variant="outline">
                        <Link :href="food.open.href">{{
                            food.open.mine ? t('change_order') : t('order')
                        }}</Link>
                    </Button>
                </div>
            </template>
            <p v-else-if="!food.next" class="text-muted-foreground">
                {{ t('food_empty') }}
            </p>
            <p
                v-if="food.next"
                class="flex items-center gap-2 text-muted-foreground"
            >
                <LiveIndicator variant="warn" :pulse="false" />
                {{
                    t('food_next', {
                        title: food.next.title,
                        time: hm(food.next.opensAt),
                    })
                }}
            </p>
        </div>
    </Panel>
</template>
