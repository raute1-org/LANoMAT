<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import PageContainer from '@/components/PageContainer.vue';
import Panel from '@/components/Panel.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Textarea } from '@/components/ui/textarea';
import { formatInstant } from '@/lib/datetime';
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
    return formatInstant(iso, { dateStyle: 'short', timeStyle: 'short' });
}
</script>

<template>
    <Head :title="`${labels.title} — ${event.name}`" />

    <PageContainer width="default" :title="`${labels.title} — ${event.name}`">
        <Panel class="mt-6" :title="labels.create_title">
            <div class="border-t border-border p-4">
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
            </div>
        </Panel>

        <div
            v-if="posts.length === 0"
            class="mt-6 rounded-md border border-dashed border-border p-6 text-center"
        >
            <p class="text-sm text-muted-foreground">
                {{ labels.empty }}
            </p>
        </div>

        <ul
            v-else
            class="mt-4 divide-y divide-border rounded-md border border-border bg-card"
        >
            <li
                v-for="post in posts"
                :key="post.id"
                class="flex items-start justify-between gap-4 px-4 py-2.5"
            >
                <div class="min-w-0 space-y-0.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <p class="font-medium">{{ post.title }}</p>
                        <Badge v-if="post.mine" variant="outline">
                            {{ labels.mine_badge }}
                        </Badge>
                    </div>
                    <p class="text-sm text-muted-foreground">
                        <span v-if="post.game">{{ post.game }} · </span>
                        <span>{{ post.userName }}</span>
                    </p>
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
                </div>

                <Button
                    v-if="post.mine"
                    size="sm"
                    variant="destructive"
                    class="shrink-0"
                    @click="remove(post)"
                >
                    {{ labels.delete }}
                </Button>
            </li>
        </ul>
    </PageContainer>
</template>
