<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { show as showScene } from '@/routes/screen/control';

type Scene = {
    id: number;
    type: string;
    typeLabel: string;
    enabled: boolean;
};

const props = defineProps<{
    event: { name: string; slug: string };
    scenes: Scene[];
    labels: Record<string, string>;
}>();

function showNow(scene: Scene) {
    useForm({}).post(
        showScene.url({ event: props.event.slug, scene: scene.id }),
        {
            preserveScroll: true,
        },
    );
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-2xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

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
