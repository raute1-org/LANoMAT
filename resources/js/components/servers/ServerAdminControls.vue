<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { changeMap, reloadMap, restartRound } from '@/routes/servers';
import type { GameServerDto } from '@/types';

// Admin shortcuts of one permanent server. The parent only renders this when the
// server sent `canManage`; the routes authorize again (policy `configure`).
const props = defineProps<{
    server: GameServerDto;
    admin: NonNullable<GameServerDto['admin']>;
    maps: string[];
    labels: Record<string, string>;
}>();

type Pending = 'restart' | 'map' | 'reload';

const pending = ref<Pending | null>(null);
const busy = ref(false);
const map = ref<string>('');
const workshopId = ref('');

const workshopInvalid = computed(
    () => workshopId.value !== '' && !/^\d{6,12}$/.test(workshopId.value),
);
const canChangeMap = computed(
    () =>
        props.admin.running &&
        !workshopInvalid.value &&
        (map.value !== '' || workshopId.value !== ''),
);

const confirmText = computed(() => {
    switch (pending.value) {
        case 'restart':
            return props.labels.admin_restart_confirm;
        case 'map':
            return props.labels.admin_map_confirm;
        case 'reload':
            return props.labels.admin_map_confirm;
        default:
            return '';
    }
});

function run() {
    if (pending.value === null) {
        return;
    }

    const kind = pending.value;
    const options = {
        preserveScroll: true,
        onStart: () => (busy.value = true),
        onFinish: () => {
            busy.value = false;
            pending.value = null;
        },
    };

    // Success and error come back as the server's flash toast.
    if (kind === 'restart') {
        router.post(restartRound.url(props.server.id), {}, options);
    } else if (kind === 'reload') {
        router.post(reloadMap.url(props.server.id), {}, options);
    } else {
        router.post(
            changeMap.url(props.server.id),
            workshopId.value !== ''
                ? { workshop_id: workshopId.value }
                : { map: map.value },
            options,
        );
    }
}

const dialogOpen = computed({
    get: () => pending.value !== null,
    set: (open: boolean) => {
        if (!open && !busy.value) {
            pending.value = null;
        }
    },
});
</script>

<template>
    <section
        class="space-y-3 rounded-md border border-dashed border-border bg-muted/20 p-3"
        :aria-label="labels.admin_heading"
    >
        <h3 class="text-sm font-medium text-muted-foreground">
            {{ labels.admin_heading }}
        </h3>

        <p v-if="!admin.running" class="text-sm text-muted-foreground">
            {{ labels.admin_not_running }}
        </p>

        <div class="flex flex-wrap items-center gap-2">
            <Button
                type="button"
                size="sm"
                variant="outline"
                :disabled="!admin.running || busy"
                @click="pending = 'restart'"
            >
                {{ labels.admin_restart_round }}
            </Button>
            <Button
                v-if="admin.canReloadMap"
                type="button"
                size="sm"
                variant="outline"
                :disabled="!admin.running || busy"
                @click="pending = 'reload'"
            >
                {{ labels.admin_reload_map }}
            </Button>
        </div>

        <form
            class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-end"
            @submit.prevent="canChangeMap && (pending = 'map')"
        >
            <div class="grid gap-1.5">
                <Label :for="`map-${server.id}`">{{
                    labels.admin_map_label
                }}</Label>
                <Select v-model="map" :disabled="workshopId !== ''">
                    <SelectTrigger :id="`map-${server.id}`" class="w-full">
                        <SelectValue
                            :placeholder="labels.admin_map_placeholder"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem v-for="m in maps" :key="m" :value="m">
                            <span class="font-mono">{{ m }}</span>
                        </SelectItem>
                    </SelectContent>
                </Select>
            </div>
            <div class="grid gap-1.5">
                <Label :for="`workshop-${server.id}`">{{
                    labels.admin_workshop_label
                }}</Label>
                <Input
                    :id="`workshop-${server.id}`"
                    v-model="workshopId"
                    class="font-mono"
                    inputmode="numeric"
                    autocomplete="off"
                    maxlength="12"
                    :aria-invalid="workshopInvalid"
                    :aria-describedby="`workshop-hint-${server.id}`"
                />
                <p
                    :id="`workshop-hint-${server.id}`"
                    class="text-xs"
                    :class="
                        workshopInvalid
                            ? 'text-destructive'
                            : 'text-muted-foreground'
                    "
                >
                    {{ labels.admin_workshop_hint }}
                </p>
            </div>
            <Button
                type="submit"
                size="sm"
                variant="outline"
                :disabled="!canChangeMap || busy"
            >
                {{ labels.admin_change_map }}
            </Button>
        </form>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>{{
                        server.title ?? server.game ?? labels.title
                    }}</DialogTitle>
                    <DialogDescription>{{ confirmText }}</DialogDescription>
                </DialogHeader>
                <DialogFooter class="gap-2">
                    <Button
                        type="button"
                        variant="outline"
                        :disabled="busy"
                        @click="pending = null"
                    >
                        {{ labels.admin_cancel }}
                    </Button>
                    <Button type="button" :disabled="busy" @click="run">
                        {{ busy ? labels.admin_working : labels.admin_confirm }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
