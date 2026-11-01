<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { MonitorDown, X } from '@lucide/vue';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useDesktopClient } from '@/composables/useDesktopClient';

/**
 * Dismissible one-row hint to the desktop app. Shown only when a matching build for the visitor's
 * desktop OS is published (`desktopClient.available`, false on phones and tablets). A dismissal is
 * remembered per browser and per client version, so a new release shows the hint once more.
 * Storage is optional: without it the hint simply shows again on the next visit.
 */
const props = defineProps<{
    labels: Record<string, string>;
}>();

const STORAGE_KEY = 'lanomat.desktop-hint.dismissed';

const { client } = useDesktopClient();

// Hidden until mounted so the stored dismissal is read before the first paint of the card.
const ready = ref(false);
const dismissedVersion = ref<string | null>(null);

onMounted(() => {
    try {
        dismissedVersion.value = window.localStorage.getItem(STORAGE_KEY);
    } catch {
        // Storage blocked or unavailable: behave as if nothing was dismissed.
    }

    ready.value = true;
});

const visible = computed(
    () =>
        ready.value &&
        client.value?.available === true &&
        client.value.version !== null &&
        dismissedVersion.value !== client.value.version,
);

const osLabel = computed(() =>
    client.value?.os ? (props.labels[`os_${client.value.os}`] ?? '') : '',
);

function dismiss(): void {
    const version = client.value?.version ?? null;

    dismissedVersion.value = version;

    try {
        if (version !== null) {
            window.localStorage.setItem(STORAGE_KEY, version);
        }
    } catch {
        // Not remembered; the hint is hidden for this page view only.
    }
}
</script>

<template>
    <aside
        v-if="visible && client"
        class="flex items-start gap-4 rounded-md border border-border bg-card p-4"
        aria-labelledby="desktop-client-hint-title"
        data-test="desktop-client-hint"
    >
        <MonitorDown
            class="mt-0.5 size-5 shrink-0 text-muted-foreground"
            aria-hidden="true"
        />
        <div class="min-w-0 flex-1">
            <h2
                id="desktop-client-hint-title"
                class="text-base font-semibold tracking-tight"
            >
                {{ labels.heading }}
            </h2>
            <p class="mt-1 text-sm text-muted-foreground">
                {{ labels.body.replace(':os', osLabel) }}
            </p>
            <!-- Outline, not amber: the dashboard already carries its one primary action (design.md: amber is rationed). -->
            <Button as-child class="mt-3" size="sm" variant="outline">
                <Link :href="client.url">{{ labels.cta }}</Link>
            </Button>
        </div>
        <Button
            type="button"
            variant="ghost"
            size="icon-sm"
            :aria-label="labels.dismiss"
            data-test="desktop-client-hint-dismiss"
            @click="dismiss"
        >
            <X aria-hidden="true" />
        </Button>
    </aside>
</template>
