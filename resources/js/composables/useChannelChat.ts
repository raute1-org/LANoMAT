import { echo } from '@laravel/echo-vue';
import { onUnmounted, ref, watch } from 'vue';
import type { Ref } from 'vue';
import {
    WebVoiceHttpError,
    webVoiceRequest,
    webVoiceUrls,
} from '@/lib/webVoiceHttp';
import type { WebVoiceMessageDto } from '@/types';

export type ChatFailure = 'load' | 'rate' | 'send' | null;

/**
 * Text chat of one channel: history over HTTP, live messages over the private Reverb channel
 * `web-voice.channel.{id}` (authorized by the same policy as the HTTP endpoints). The list is
 * deduplicated by id, because the sender gets the message from the POST response and from the push.
 */
export function useChannelChat(
    channelId: Ref<number | null>,
    pageSize: number,
) {
    const messages = ref<WebVoiceMessageDto[]>([]);
    const loading = ref(false);
    const failure = ref<ChatFailure>(null);
    const hasOlder = ref(false);
    const sending = ref(false);
    let subscribedTo: number | null = null;

    function merge(incoming: WebVoiceMessageDto[]): void {
        const known = new Set(messages.value.map((message) => message.id));
        const fresh = incoming.filter((message) => !known.has(message.id));

        messages.value = [...messages.value, ...fresh].sort(
            (a, b) => a.id - b.id,
        );
    }

    function unsubscribe(): void {
        if (subscribedTo !== null) {
            echo().leave(`web-voice.channel.${subscribedTo}`);
            subscribedTo = null;
        }
    }

    function subscribe(id: number): void {
        subscribedTo = id;
        echo()
            .private(`web-voice.channel.${id}`)
            .listen(
                '.message.posted',
                (payload: { message: WebVoiceMessageDto }) => {
                    merge([payload.message]);
                },
            )
            .listen('.message.deleted', (payload: { message_id: number }) => {
                messages.value = messages.value.filter(
                    (message) => message.id !== payload.message_id,
                );
            });
    }

    async function load(id: number, before?: number): Promise<void> {
        loading.value = true;
        failure.value = null;

        try {
            const url =
                webVoiceUrls.messages(id) + (before ? `?before=${before}` : '');
            const result = await webVoiceRequest<{
                messages: WebVoiceMessageDto[];
            }>('GET', url);

            merge(result.messages);
            hasOlder.value = result.messages.length >= pageSize;
        } catch {
            failure.value = 'load';
        } finally {
            loading.value = false;
        }
    }

    async function loadOlder(): Promise<void> {
        const id = channelId.value;
        const oldest = messages.value[0];

        if (id !== null && oldest) {
            await load(id, oldest.id);
        }
    }

    async function send(body: string): Promise<boolean> {
        const id = channelId.value;

        if (id === null) {
            return false;
        }

        sending.value = true;
        failure.value = null;

        try {
            const result = await webVoiceRequest<{
                message: WebVoiceMessageDto;
            }>('POST', webVoiceUrls.messages(id), { body });

            merge([result.message]);

            return true;
        } catch (error) {
            failure.value =
                error instanceof WebVoiceHttpError && error.status === 429
                    ? 'rate'
                    : 'send';

            return false;
        } finally {
            sending.value = false;
        }
    }

    async function remove(messageId: number): Promise<void> {
        try {
            await webVoiceRequest('DELETE', webVoiceUrls.message(messageId));
            messages.value = messages.value.filter(
                (message) => message.id !== messageId,
            );
        } catch {
            failure.value = 'send';
        }
    }

    watch(
        channelId,
        (id) => {
            unsubscribe();
            messages.value = [];
            hasOlder.value = false;
            failure.value = null;

            if (id !== null) {
                subscribe(id);
                void load(id);
            }
        },
        { immediate: true },
    );

    onUnmounted(unsubscribe);

    function reload(): Promise<void> {
        return channelId.value !== null
            ? load(channelId.value)
            : Promise.resolve();
    }

    return {
        messages,
        loading,
        failure,
        hasOlder,
        sending,
        send,
        remove,
        loadOlder,
        reload,
    };
}
