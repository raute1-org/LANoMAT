<script setup lang="ts">
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
    buildDesktopFile,
    buildRegFile,
    isValidHandlerPath,
    LINUX_REGISTER_COMMANDS,
} from '@/lib/ut2004Handler';
import type { HandlerOs } from '@/lib/ut2004Handler';

// Client-side only: builds a .reg / .desktop file in the browser (Blob), no upload.
const props = defineProps<{
    labels: Record<string, string>;
}>();

const open = defineModel<boolean>('open', { required: true });

const os = ref<HandlerOs>('windows');
const path = ref('');
const commandsCopied = ref(false);

const valid = computed(() => isValidHandlerPath(os.value, path.value));
const showError = computed(() => path.value.trim() !== '' && !valid.value);

function label(key: string): string {
    return props.labels[`handler_${key}`] ?? key;
}

function download(name: string, text: string, type: string) {
    const url = URL.createObjectURL(new Blob([text], { type }));
    const a = document.createElement('a');
    a.href = url;
    a.download = name;
    a.click();
    URL.revokeObjectURL(url);
}

function downloadFile() {
    if (os.value === 'windows') {
        const text = buildRegFile(path.value);

        if (text) {
            download('ut2004-handler.reg', text, 'text/plain;charset=utf-8');
        }

        return;
    }

    const text = buildDesktopFile(path.value);

    if (text) {
        download('ut2004-handler.desktop', text, 'text/plain;charset=utf-8');
    }
}

async function copyCommands() {
    try {
        await navigator.clipboard.writeText(LINUX_REGISTER_COMMANDS.join('\n'));
        commandsCopied.value = true;
        setTimeout(() => {
            commandsCopied.value = false;
        }, 1800);
    } catch {
        commandsCopied.value = false;
    }
}
</script>

<template>
    <Dialog v-model:open="open">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ label('title') }}</DialogTitle>
                <DialogDescription>{{ label('intro') }}</DialogDescription>
            </DialogHeader>

            <div class="space-y-4">
                <div
                    role="radiogroup"
                    :aria-label="label('os_label')"
                    class="inline-flex rounded-md border border-border bg-muted/30 p-0.5"
                >
                    <button
                        v-for="candidate in ['windows', 'linux'] as const"
                        :key="candidate"
                        type="button"
                        role="radio"
                        :aria-checked="os === candidate"
                        class="rounded-sm px-3 py-1 text-sm font-medium text-muted-foreground focus-visible:ring-2 focus-visible:ring-ring focus-visible:outline-none aria-checked:bg-background aria-checked:text-foreground aria-checked:shadow-xs"
                        @click="os = candidate"
                    >
                        {{ label(`os_${candidate}`) }}
                    </button>
                </div>

                <div class="space-y-1.5">
                    <Label for="ut2004-path">{{
                        label(`path_label_${os}`)
                    }}</Label>
                    <Input
                        id="ut2004-path"
                        v-model="path"
                        class="font-mono"
                        autocomplete="off"
                        spellcheck="false"
                        :placeholder="label(`path_placeholder_${os}`)"
                        :aria-invalid="showError"
                    />
                    <p v-if="showError" class="text-sm text-destructive">
                        {{ label('path_invalid') }}
                    </p>
                </div>

                <p
                    v-if="os === 'windows'"
                    class="text-sm text-muted-foreground"
                >
                    {{ label('reg_hint') }}
                </p>
                <div v-else class="space-y-2 text-sm text-muted-foreground">
                    <p>{{ label('linux_hint') }}</p>
                    <pre
                        class="overflow-x-auto rounded-md bg-muted p-2 font-mono text-xs text-foreground"
                        >{{ LINUX_REGISTER_COMMANDS.join('\n') }}</pre>
                    <Button
                        type="button"
                        size="sm"
                        variant="outline"
                        @click="copyCommands"
                    >
                        {{
                            commandsCopied
                                ? labels.copied
                                : label('copy_commands')
                        }}
                    </Button>
                </div>
            </div>

            <DialogFooter>
                <Button type="button" variant="ghost" @click="open = false">
                    {{ label('close') }}
                </Button>
                <Button type="button" :disabled="!valid" @click="downloadFile">
                    {{
                        os === 'windows'
                            ? label('download_reg')
                            : label('download_desktop')
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
