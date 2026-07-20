<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';

export type ConnectionProvider = {
    provider: string;
    label: string;
    linked: boolean;
    nickname: string | null;
    needsReauth: boolean;
    redirectUrl: string;
    unlinkUrl: string;
};

const props = defineProps<{
    connection: ConnectionProvider;
    labels: Record<string, string>;
}>();

function unlink() {
    router.delete(props.connection.unlinkUrl, { preserveScroll: true });
}
</script>

<template>
    <Card data-test="connection-card" :data-provider="connection.provider">
        <CardHeader>
            <div class="flex items-center justify-between gap-3">
                <CardTitle>{{ connection.label }}</CardTitle>
                <span
                    class="rounded-full border px-2 py-0.5 text-xs font-medium"
                    :class="
                        connection.linked
                            ? 'border-ok/30 bg-ok/10 text-ok'
                            : 'border-border bg-muted text-muted-foreground'
                    "
                >
                    {{
                        connection.linked
                            ? labels.linked_label
                            : labels.not_linked_label
                    }}
                </span>
            </div>
            <CardDescription v-if="connection.linked && connection.nickname">
                {{ connection.nickname }}
            </CardDescription>
        </CardHeader>

        <CardContent class="space-y-4">
            <!-- needsReauth: the one rationed signal-amber use on this page -->
            <div
                v-if="connection.needsReauth"
                class="flex flex-wrap items-center justify-between gap-3 rounded-md border border-primary/40 bg-primary/10 px-3 py-2"
                role="alert"
                data-test="reauth-warning"
            >
                <p class="text-sm text-primary">
                    {{ labels.reauth_warning }}
                </p>
                <Button as-child size="sm" data-test="reauth-button">
                    <a :href="connection.redirectUrl">{{
                        labels.reauth_button
                    }}</a>
                </Button>
            </div>

            <div class="flex items-center gap-3">
                <Button
                    v-if="!connection.linked"
                    as-child
                    variant="outline"
                    data-test="link-button"
                >
                    <a :href="connection.redirectUrl">{{
                        labels.link_button
                    }}</a>
                </Button>

                <Button
                    v-else
                    variant="ghost"
                    data-test="unlink-button"
                    @click="unlink"
                >
                    {{ labels.unlink_button }}
                </Button>
            </div>
        </CardContent>
    </Card>
</template>
