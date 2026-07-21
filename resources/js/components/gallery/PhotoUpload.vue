<script setup lang="ts">
/**
 * The gallery's upload control — a multi-file picker plus an optional shared
 * caption, applied to every file in the batch (the backend loops one
 * UploadPhoto::handle() call per file). This is the page's single rationed
 * amber CTA (docs/design.md: "amber is rationed").
 */
import { useForm } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Label } from '@/components/ui/label';
import { store as storeGallery } from '@/routes/gallery';

const props = defineProps<{
    eventSlug: string;
    labels: Record<string, string>;
}>();

const form = useForm<{ photos: File[]; caption: string }>({
    photos: [],
    caption: '',
});

function onFilesChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.photos = Array.from(target.files ?? []);
}

function submit(): void {
    form.post(storeGallery.url({ event: props.eventSlug }), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => form.reset(),
    });
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ labels.upload_title }}</CardTitle>
        </CardHeader>
        <CardContent>
            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="photo-upload">{{ labels.upload_label }}</Label>
                    <input
                        id="photo-upload"
                        type="file"
                        accept="image/*"
                        multiple
                        required
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm file:mr-3 file:rounded-sm file:border-0 file:bg-secondary file:px-3 file:py-1.5 file:text-sm file:font-medium focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                        @change="onFilesChange"
                    />
                    <p
                        v-if="form.errors.photos"
                        class="text-sm text-destructive"
                    >
                        {{ form.errors.photos }}
                    </p>
                </div>

                <div class="grid gap-2">
                    <Label for="photo-caption">{{
                        labels.upload_caption_label
                    }}</Label>
                    <input
                        id="photo-caption"
                        v-model="form.caption"
                        type="text"
                        class="rounded-md border border-input bg-transparent px-3 py-2 text-sm focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none"
                    />
                </div>

                <Button type="submit" :disabled="form.processing">
                    {{
                        form.processing
                            ? labels.uploading
                            : labels.upload_submit
                    }}
                </Button>
            </form>
        </CardContent>
    </Card>
</template>
