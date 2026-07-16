<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { setStatus, show as showScene } from '@/routes/screen/control';
import { draw as drawTombola } from '@/routes/screen/control/tombola';
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

type TombolaPrize = {
    id: number;
    title: string;
};

type StatusLevel = 'ok' | 'degraded' | 'down';

type StatusSignal = {
    component: string;
    level: StatusLevel;
    message: string | null;
};

const props = defineProps<{
    event: { name: string; slug: string };
    scenes: Scene[];
    foodOrders: FoodOrder[];
    tombolaPrizes: TombolaPrize[];
    statusComponents: string[];
    statusSignals: Record<string, StatusSignal>;
    statusLevels: { value: StatusLevel; label: string }[];
    labels: Record<string, string>;
    triggerLabels: Record<string, string>;
    statusLabels: Record<string, string>;
    statusComponentLabels: Record<string, string>;
}>();

const statusMessages = reactive<Record<string, string>>(
    Object.fromEntries(
        props.statusComponents.map((component) => [
            component,
            props.statusSignals[component]?.message ?? '',
        ]),
    ),
);

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

function drawTombolaPrize(prize: TombolaPrize) {
    useForm({}).post(
        drawTombola.url({ event: props.event.slug, tombolaPrize: prize.id }),
        {
            preserveScroll: true,
        },
    );
}

function setStatusLevel(component: string, level: StatusLevel) {
    useForm({
        component,
        level,
        message: statusMessages[component] || '',
    }).post(setStatus.url({ event: props.event.slug }), {
        preserveScroll: true,
    });
}

function currentLevel(component: string): StatusLevel {
    return props.statusSignals[component]?.level ?? 'ok';
}

function levelBadgeVariant(
    level: StatusLevel,
): 'default' | 'destructive' | 'outline' {
    if (level === 'down') {
        return 'destructive';
    }

    if (level === 'degraded') {
        return 'outline';
    }

    return 'default';
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

                <Card>
                    <CardHeader>
                        <CardTitle>{{
                            triggerLabels.tombola_draw_title
                        }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p
                            v-if="tombolaPrizes.length === 0"
                            class="text-sm text-muted-foreground"
                        >
                            {{ triggerLabels.tombola_draw_empty }}
                        </p>
                        <div v-else class="flex flex-wrap gap-2">
                            <Button
                                v-for="prize in tombolaPrizes"
                                :key="prize.id"
                                size="sm"
                                @click="drawTombolaPrize(prize)"
                            >
                                {{ triggerLabels.tombola_draw_button }}:
                                {{ prize.title }}
                            </Button>
                        </div>
                    </CardContent>
                </Card>
            </div>
        </section>

        <section class="mt-8">
            <h2 class="text-lg font-semibold tracking-tight">
                {{ statusLabels.title }}
            </h2>

            <div class="mt-4 space-y-4">
                <Card v-for="component in statusComponents" :key="component">
                    <CardHeader>
                        <div class="flex items-center justify-between gap-4">
                            <CardTitle>{{
                                statusComponentLabels[component]
                            }}</CardTitle>
                            <Badge
                                :variant="
                                    levelBadgeVariant(currentLevel(component))
                                "
                            >
                                {{
                                    statusLevels.find(
                                        (l) =>
                                            l.value === currentLevel(component),
                                    )?.label
                                }}
                            </Badge>
                        </div>
                    </CardHeader>
                    <CardContent class="space-y-3">
                        <div class="grid gap-2">
                            <Label :for="`status-message-${component}`">{{
                                statusLabels.message_label
                            }}</Label>
                            <Textarea
                                :id="`status-message-${component}`"
                                v-model="statusMessages[component]"
                                :placeholder="statusLabels.message_placeholder"
                                maxlength="500"
                            />
                        </div>

                        <div class="flex flex-wrap gap-2">
                            <Button
                                v-for="level in statusLevels"
                                :key="level.value"
                                size="sm"
                                :variant="
                                    level.value === currentLevel(component)
                                        ? 'default'
                                        : 'outline'
                                "
                                @click="setStatusLevel(component, level.value)"
                            >
                                {{ level.label }}
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
