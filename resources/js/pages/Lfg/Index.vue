<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { destroy as destroyPost, store as storePost } from '@/routes/lfg';
import type { LfgPostDto } from '@/types';

const props = defineProps<{
    event: { name: string; slug: string };
    posts: LfgPostDto[];
    labels: Record<string, string>;
}>();

const form = useForm({
    title: '',
    game: '',
    body: '',
    slots_needed: '' as number | string,
    duration_hours: '' as number | string,
});

function submit() {
    form.post(storePost.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}

function remove(post: LfgPostDto) {
    useForm({}).delete(destroyPost.url({ lfgPost: post.id }), {
        preserveScroll: true,
    });
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('de-DE');
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <main class="mx-auto max-w-3xl px-4 py-12">
        <h1 class="text-3xl font-bold tracking-tight">
            {{ labels.title }} — {{ event.name }}
        </h1>

        <Card class="mt-8">
            <CardHeader>
                <CardTitle>{{ labels.create_title }}</CardTitle>
            </CardHeader>
            <CardContent>
                <form class="space-y-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="lfg-title">{{ labels.title_field }}</Label>
                        <Input
                            id="lfg-title"
                            v-model="form.title"
                            :placeholder="labels.title_placeholder"
                            maxlength="120"
                            required
                        />
                        <p
                            v-if="form.errors.title"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.title }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="lfg-game">{{ labels.game }}</Label>
                        <Input
                            id="lfg-game"
                            v-model="form.game"
                            :placeholder="labels.game_placeholder"
                            maxlength="64"
                        />
                        <p
                            v-if="form.errors.game"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.game }}
                        </p>
                    </div>

                    <div class="grid gap-2">
                        <Label for="lfg-body">{{ labels.body }}</Label>
                        <Textarea
                            id="lfg-body"
                            v-model="form.body"
                            :placeholder="labels.body_placeholder"
                            maxlength="1000"
                        />
                        <p
                            v-if="form.errors.body"
                            class="text-sm text-destructive"
                        >
                            {{ form.errors.body }}
                        </p>
                    </div>

                    <div class="grid grid-cols-2 gap-4">
                        <div class="grid gap-2">
                            <Label for="lfg-slots">{{
                                labels.slots_needed
                            }}</Label>
                            <Input
                                id="lfg-slots"
                                v-model="form.slots_needed"
                                type="number"
                                min="1"
                            />
                            <p
                                v-if="form.errors.slots_needed"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.slots_needed }}
                            </p>
                        </div>

                        <div class="grid gap-2">
                            <Label for="lfg-duration">{{
                                labels.duration_hours
                            }}</Label>
                            <Input
                                id="lfg-duration"
                                v-model="form.duration_hours"
                                type="number"
                                min="1"
                                max="168"
                            />
                            <p
                                v-if="form.errors.duration_hours"
                                class="text-sm text-destructive"
                            >
                                {{ form.errors.duration_hours }}
                            </p>
                        </div>
                    </div>

                    <Button type="submit" :disabled="form.processing">
                        {{ labels.submit }}
                    </Button>
                </form>
            </CardContent>
        </Card>

        <div
            v-if="posts.length === 0"
            class="mt-8 rounded-lg border border-dashed border-border p-8 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>

        <div v-else class="mt-8 space-y-4">
            <Card v-for="post in posts" :key="post.id">
                <CardHeader>
                    <div class="flex items-center justify-between gap-4">
                        <CardTitle>{{ post.title }}</CardTitle>
                        <Badge v-if="post.mine" variant="outline">
                            {{ labels.mine_badge }}
                        </Badge>
                    </div>
                    <CardDescription>
                        <span v-if="post.game">{{ post.game }} · </span>
                        <span>{{ post.userName }}</span>
                    </CardDescription>
                </CardHeader>

                <CardContent class="space-y-3">
                    <p v-if="post.body" class="text-sm">{{ post.body }}</p>

                    <p
                        v-if="post.slotsNeeded"
                        class="text-sm text-muted-foreground"
                    >
                        {{ labels.slots_needed }}:
                        <span class="font-mono tabular-nums">{{
                            post.slotsNeeded
                        }}</span>
                    </p>

                    <p class="text-sm text-muted-foreground">
                        {{ labels.expires_at }}:
                        <span class="font-mono tabular-nums">{{
                            formatDateTime(post.expiresAt)
                        }}</span>
                    </p>

                    <Button
                        v-if="post.mine"
                        size="sm"
                        variant="destructive"
                        @click="remove(post)"
                    >
                        {{ labels.delete }}
                    </Button>
                </CardContent>
            </Card>
        </div>
    </main>
</template>
