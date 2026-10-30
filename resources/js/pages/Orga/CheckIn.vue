<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { QrcodeStream } from 'vue-qrcode-reader';
import PageContainer from '@/components/PageContainer.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { store as storeCheckIn } from '@/routes/orga/checkin';

const props = defineProps<{
    event: { name: string; slug: string };
    labels: Record<string, string>;
}>();

const form = useForm({ qr_token: '' });

function submit() {
    form.post(storeCheckIn.url({ event: props.event.slug }), {
        preserveScroll: true,
        onSuccess: () => form.reset('qr_token'),
    });
}

function onDetect(detected: { rawValue: string }[]) {
    if (detected.length > 0) {
        form.qr_token = detected[0].rawValue;
        submit();
    }
}
</script>

<template>
    <Head :title="labels.title" />

    <PageContainer width="narrow" :title="`${labels.title} — ${event.name}`">
        <div
            class="mt-6 aspect-square overflow-hidden rounded-lg border border-border"
        >
            <QrcodeStream @detect="onDetect" />
        </div>

        <form class="mt-6 space-y-3" @submit.prevent="submit">
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
