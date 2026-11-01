<script setup lang="ts">
import { computed } from 'vue';
import { Button } from '@/components/ui/button';
import UserAvatar from '@/components/UserAvatar.vue';
import type { SuggestionDto } from '@/types';

const props = defineProps<{
    suggestion: SuggestionDto;
    labels: Record<string, string>;
    processing?: boolean;
}>();

const emit = defineEmits<{
    add: [];
}>();

const reasonLabels = computed(() =>
    props.suggestion.reasons.map(
        (reason) => props.labels[`reason_${reason}`] ?? reason,
    ),
);
</script>

<template>
    <li class="flex items-center justify-between gap-4 px-4 py-3">
        <div class="flex min-w-0 items-center gap-3">
            <UserAvatar :name="suggestion.name" :src="suggestion.avatarUrl" />
            <div class="min-w-0">
                <p class="truncate font-medium">{{ suggestion.name }}</p>
                <p class="truncate text-sm text-muted-foreground">
                    {{ reasonLabels.join(' · ') }} ·
                    {{
                        labels.shared_count.replace(
                            ':count',
                            String(suggestion.shared),
                        )
                    }}
                </p>
            </div>
        </div>

        <Button
            size="sm"
            variant="outline"
            :disabled="processing"
            @click="emit('add')"
        >
            {{ labels.add }}
        </Button>
    </li>
</template>
