<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showScene } from '@/routes/screen/control';
import { checkinOpen, foodReady } from '@/routes/screen/control/trigger';

type Scene = {
    id: number;
    type: string;
    typeLabel: string;
    enabled: boolean;
};

type FoodOrder = {
    id: number;
    title: string;
};

const props = defineProps<{
    event: { name: string; slug: string };
    scenes: Scene[];
    foodOrders: FoodOrder[];
    labels: Record<string, string>;
    triggerLabels: Record<string, string>;
}>();

function showNow(scene: Scene) {
    useForm({}).post(
        showScene.url({ event: props.event.slug, scene: scene.id }),
        {
            preserveScroll: true,
        },
    );
}

function triggerFoodReady(order: FoodOrder) {
    useForm({}).post(
        foodReady.url({ event: props.event.slug, foodOrder: order.id }),
        {
            preserveScroll: true,
        },
    );
}

function triggerCheckinOpen() {
    useForm({}).post(checkinOpen.url({ event: props.event.slug }), {
        preserveScroll: true,
    });
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <section class="mt-8">
            <h2 class="text-lg font-semibold tracking-tight">
                {{ triggerLabels.title }}
            </h2>

            <div class="mt-4 space-y-4">
                <Card>
                    <CardHeader>
                        <CardTitle>{{
                            triggerLabels.checkin_open_title
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <Button size="sm" @click="triggerCheckinOpen">
                            {{ triggerLabels.checkin_open_button }}
                        </Button>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>{{
                            triggerLabels.food_ready_title
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="foodOrders.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ triggerLabels.food_ready_empty }}
                        </p>
                        <div v-else class="flex flex-wrap gap-2">
                            <Button
                                v-for="order in foodOrders"
                                :key="order.id"
                                size="sm"
                                @click="triggerFoodReady(order)"
                            >
                                {{ triggerLabels.food_ready_button }}:
                                {{ order.title }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>

        <p
            v-if="scenes.length === 0"
            class="mt-8 text-sm text-muted-foreground"
        >
            {{ labels.empty }}
        </p>

        <div v-else class="mt-8 space-y-4">
            <Card v-for="scene in scenes" :key="scene.id">
                <CardHeader>
                    <div class="flex items-center justify-between gap-4">
                        <CardTitle>{{ scene.typeLabel }}</CardTitle>
                        <Badge :variant="scene.enabled ? 'default' : 'outline'">
                            {{
                                scene.enabled ? labels.enabled : labels.disabled
                            }}
                        </Badge>
                    </div>
                </CardHeader>

                <CardContent>
                    <Button size="sm" @click="showNow(scene)">
                        {{ labels.show_now }}
                    </Button>
                </CardContent>
            </Card>
        </div>
    </main>
</template>
