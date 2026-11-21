<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import FoodPaymentState from '@/components/catering/FoodPaymentState.vue';
import LiveIndicator from '@/components/LiveIndicator.vue';
import Panel from '@/components/Panel.vue';
import { Button } from '@/components/ui/button';
import { formatClock } from '@/lib/datetime';
import { show as cateringShow } from '@/routes/catering';
import { register as eventsRegister } from '@/routes/events';
import type { FoodNowDto } from '@/types/now';
import { useNowLabels } from './useNowLabels';

defineProps<{
    food: FoodNowDto;
    eventSlug: string;
    registrationOpen?: boolean;
}>();

const t = useNowLabels();

const payLabels = (): Record<string, string> => ({
    pay_included: t('pay_included'),
    pay_nothing_due: t('pay_nothing_due'),
    pay_cash: t('pay_cash'),
    pay_due: t('pay_due'),
    pay_with_paypal: t('pay_with_paypal'),
    pay_at_orga: t('pay_at_orga'),
    pay_reference_hint: t('pay_reference_hint'),
    pay_copy: t('pay_copy'),
    copy_reference: t('copy_reference'),
    pay_copied: t('pay_copied'),
    pay_copy_failed: t('pay_copy_failed'),
    new_tab: t('new_tab'),
});
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
                        class="font-mono text-xs text-warn tabular-nums"
                    >
                        {{
                            t('closes_at', {
                                time: formatClock(food.open.closesAt),
                            })
                        }}
                    </span>
                    <span
                        class="font-mono text-xs text-muted-foreground tabular-nums"
                    >
                        {{
                            t('ordered_count', { count: food.open.orderCount })
                        }}
                    </span>
                </div>
                <div
                    class="flex flex-wrap items-center justify-between gap-2 rounded-sm bg-muted px-3 py-2"
                >
                    <span class="text-muted-foreground">
                        {{
                            food.open.mine
                                ? t('your_order', { order: food.open.mine })
                                : t('nothing_ordered')
                        }}
                    </span>
                    <Button
                        v-if="food.open.orderable"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="food.open.href">{{
                            food.open.mine ? t('change_order') : t('order')
                        }}</Link>
                    </Button>
                    <Button
                        v-else-if="registrationOpen"
                        as-child
                        size="sm"
                        variant="outline"
                    >
                        <Link :href="eventsRegister.url(eventSlug)">{{
                            t('registration_needed')
                        }}</Link>
                    </Button>
                    <span v-else class="text-xs text-muted-foreground">{{
                        t('registration_needed')
                    }}</span>
                </div>
                <FoodPaymentState
                    v-if="food.open.payment"
                    :payment="food.open.payment"
                    :primary="false"
                    :labels="payLabels()"
                />
            </template>
            <p v-else-if="!food.next" class="text-muted-foreground">
                {{ t('food_empty') }}
            </p>
            <p
                v-if="food.next"
                class="flex items-center gap-2 text-muted-foreground"
            >
                <LiveIndicator variant="ok" :pulse="false" />
                {{
                    t('food_next', {
                        title: food.next.title,
                        time: formatClock(food.next.opensAt),
                    })
                }}
            </p>
        </div>
    </Panel>
</template>
