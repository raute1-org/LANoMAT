<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';
import FoodRound from '@/components/catering/FoodRound.vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import type { FoodOrderDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    orders: FoodOrderDto[];
    labels: Record<string, string>;
    registration: { required: boolean; url: string };
}>();

// Only the first closed round with something to pay gets the amber button, and
// never while the "register first" banner already offers its own call to action.
const primaryPaymentId = computed<number | null>(() => {
    if (props.registration.required) {
        return null;
    }

    return (
        props.orders.find(
            (o) =>
                !o.isOpen &&
                (o.myPayment?.dueCents ?? 0) > 0 &&
                !!o.myPayment?.url,
        )?.id ?? null
    );
});
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="full" :title="`${labels.title} — ${event.name}`">
        <div
            v-if="registration.required"
            class="mt-6 flex flex-col gap-3 rounded-md border bg-card p-4 sm:flex-row sm:items-center sm:justify-between"
        >
            <p class="text-sm">{{ labels.registration_required }}</p>
            <Button as-child size="sm" class="shrink-0">
                <a :href="registration.url">{{ labels.register_link }}</a>
            </Button>
        </div>

        <div
            v-if="orders.length === 0"
            class="mt-6 rounded-md border border-dashed border-border p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>

        <div v-else class="mt-6 space-y-10">
            <FoodRound
                v-for="foodOrder in orders"
                :key="foodOrder.id"
                :food-order="foodOrder"
                :labels="labels"
                :registration-required="registration.required"
                :primary-payment="foodOrder.id === primaryPaymentId"
            />
        </div>
    </PageContainer>
</template>
