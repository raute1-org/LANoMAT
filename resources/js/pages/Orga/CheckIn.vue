<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { Camera, CameraOff } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { useQrScanner } from '@/composables/useQrScanner';
import type { ScannerState } from '@/composables/useQrScanner';
import { store as storeCheckIn } from '@/routes/orga/checkin';

interface CheckInResult {
    id: string;
    status: 'success' | 'error';
    message: string;
    name?: string;
    ticket?: string;
}

const props = defineProps<{
    event: { name: string; slug: string };
    labels: Record<string, string>;
    cameraLabels: Record<string, string>;
}>();

const form = useForm({ qr_token: '' });
const result = ref<CheckInResult | null>(null);

/** Posts one token; resolves when the response (and its flash) arrived. */
function post(token: string): Promise<void> {
    return new Promise((resolve) => {
        router.post(
            storeCheckIn.url({ event: props.event.slug }),
            { qr_token: token },
            {
                preserveScroll: true,
                preserveState: true,
                onFinish: () => resolve(),
            },
        );
    });
}

function submit() {
    form.post(storeCheckIn.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => form.reset('qr_token'),
    });
}

const { videoRef, state, start, stop } = useQrScanner({
    onDecode: async (value) => {
        if (navigator.vibrate) {
            navigator.vibrate(40);
        }

        await post(value.trim());
    },
});

const active = computed(
    () => state.value === 'starting' || state.value === 'scanning',
);

const problem = computed<string | null>(() => {
    const keys: Partial<Record<ScannerState, string>> = {
        denied: 'denied',
        no_camera: 'no_camera',
        camera_busy: 'camera_busy',
        insecure: 'insecure',
        unsupported: 'unsupported',
        error: 'error',
    };
    const key = keys[state.value];

    return key ? (props.cameraLabels[key] ?? null) : null;
});

// The server answers every scan with a `checkin` flash (name + ticket on
// success); show the latest one in the result panel next to the camera.
let stopFlash: (() => void) | null = null;

onMounted(() => {
    stopFlash = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            { checkin?: CheckInResult } | undefined;

        if (flash?.checkin) {
            result.value = flash.checkin;
        }
    });
});

onBeforeUnmount(() => {
    stopFlash?.();
    stop();
});
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer width="narrow" :title="`${labels.title} — ${event.name}`">
        <div class="mt-6 space-y-3">
            <Button v-if="!active" size="lg" class="w-full" @click="start">
                <Camera class="size-5" aria-hidden="true" />
                {{ cameraLabels.start }}
            </Button>
            <Button
                v-else
                size="lg"
                variant="outline"
                class="w-full"
                @click="stop"
            >
                <CameraOff class="size-5" aria-hidden="true" />
                {{ cameraLabels.stop }}
            </Button>

            <p
                v-if="problem"
                class="rounded-md border border-destructive/50 bg-destructive/10 p-4 text-sm text-destructive"
                role="alert"
                data-testid="scanner-problem"
            >
                {{ problem }}
            </p>

            <div
                v-show="active"
                class="relative aspect-[3/4] overflow-hidden rounded-lg border border-border bg-black sm:aspect-video"
            >
                <video
                    ref="videoRef"
                    class="size-full object-cover"
                    playsinline
                    muted
                    :aria-label="cameraLabels.video_label"
                />
                <p
                    v-if="state === 'starting'"
                    class="absolute inset-0 flex items-center justify-center text-sm text-white"
                >
                    {{ cameraLabels.starting }}
                </p>
            </div>
            <p
                v-if="state === 'scanning'"
                class="text-xs text-muted-foreground"
            >
                {{ cameraLabels.hint }}
            </p>

            <div aria-live="polite" data-testid="scan-result">
                <div
                    v-if="result"
                    :key="result.id"
                    class="rounded-lg border p-4"
                    :class="
                        result.status === 'success'
                            ? 'border-ok/50 bg-ok/10'
                            : 'border-destructive/50 bg-destructive/10'
                    "
                >
                    <template v-if="result.status === 'success'">
                        <p class="text-sm text-muted-foreground">
                            {{ cameraLabels.checked_in }}
                        </p>
                        <p
                            class="text-2xl font-semibold tracking-tight text-foreground"
                        >
                            {{ result.name }}
                        </p>
                        <p v-if="result.ticket" class="text-sm text-foreground">
                            {{ cameraLabels.ticket }}: {{ result.ticket }}
                        </p>
                    </template>
                    <p v-else class="text-base font-medium text-destructive">
                        {{ result.message }}
                    </p>
                </div>
            </div>
        </div>

        <form class="mt-8 space-y-3" @submit.prevent="submit">
            <label class="text-sm text-muted-foreground" for="token">{{
                labels.manual
            }}</label>
            <Input id="token" v-model="form.qr_token" type="text" />
            <Button type="submit" :disabled="form.processing">{{
                labels.submit
            }}</Button>
        </form>
    </PageContainer>
</template>
