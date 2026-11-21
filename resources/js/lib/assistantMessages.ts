import type {
    StreamPart,
    TextPart,
    ToolPart,
    UiMessage,
    UiPart,
} from '@/types/assistant';

/**
 * Pure reducer from the Vercel UI message stream to the page's message list.
 * No Vue, no IO, so it is covered by `node --test tests/js/assistantMessages.test.ts`.
 *
 * The server persists the authoritative history; the page reloads it when a
 * stream ends. This reducer only has to show the live turn.
 */

export function isToolPart(part: UiPart): part is ToolPart {
    return part.type.startsWith('tool-');
}

/** `tool-events_list` becomes `events_list`. */
export function toolNameOf(part: ToolPart): string {
    return part.type.slice('tool-'.length);
}

function lastAssistant(messages: UiMessage[]): UiMessage | undefined {
    const last = messages[messages.length - 1];

    return last?.role === 'assistant' ? last : undefined;
}

function ensureAssistant(messages: UiMessage[], id?: string): UiMessage {
    const existing = lastAssistant(messages);

    if (existing) {
        return existing;
    }

    const created: UiMessage = {
        id: id ?? `live-${messages.length}`,
        role: 'assistant',
        parts: [],
    };
    messages.push(created);

    return created;
}

function findTool(
    messages: UiMessage[],
    toolCallId: string | undefined,
): ToolPart | undefined {
    for (const message of messages) {
        for (const part of message.parts) {
            if (isToolPart(part) && part.toolCallId === toolCallId) {
                return part;
            }
        }
    }

    return undefined;
}

/** Appends the user's own message to the list (before the answer streams in). */
export function withUserMessage(
    messages: UiMessage[],
    text: string,
): UiMessage[] {
    return [
        ...messages,
        {
            id: `user-${messages.length}-${text.length}`,
            role: 'user',
            parts: [{ type: 'text', text }],
        },
    ];
}

/** Returns a new list with the stream part applied; unknown parts are ignored. */
export function applyStreamPart(
    current: UiMessage[],
    part: StreamPart,
): UiMessage[] {
    const messages = structuredClone(current);

    switch (part.type) {
        case 'start':
            ensureAssistant(messages, part.messageId);
            break;
        case 'text-start': {
            ensureAssistant(messages).parts.push({
                type: 'text',
                id: part.id,
                text: '',
            });
            break;
        }
        case 'text-delta': {
            const message = ensureAssistant(messages);
            const text = [...message.parts]
                .reverse()
                .find(
                    (p): p is TextPart =>
                        p.type === 'text' &&
                        (part.id === undefined || p.id === part.id),
                );

            if (text) {
                text.text += part.delta ?? '';
            } else {
                message.parts.push({
                    type: 'text',
                    id: part.id,
                    text: part.delta ?? '',
                });
            }

            break;
        }
        case 'tool-input-available': {
            ensureAssistant(messages).parts.push({
                type: `tool-${part.toolName ?? 'tool'}`,
                toolCallId: part.toolCallId ?? '',
                state: 'input-available',
                input: part.input,
            });
            break;
        }
        case 'tool-approval-request': {
            const tool = findTool(messages, part.toolCallId);

            if (tool) {
                tool.state = 'approval-requested';
            }

            break;
        }
        case 'tool-output-available': {
            const tool = findTool(messages, part.toolCallId);

            if (tool) {
                tool.state = 'output-available';
                tool.output = part.output;
            }

            break;
        }
        case 'tool-output-denied': {
            const tool = findTool(messages, part.toolCallId);

            if (tool) {
                tool.state = 'output-denied';
            }

            break;
        }
        case 'tool-output-error': {
            const tool = findTool(messages, part.toolCallId);

            if (tool) {
                tool.state = 'output-error';
                tool.errorText = part.errorText;
            }

            break;
        }
        default:
            return current;
    }

    return messages;
}

/** The German word the tool chip shows for a tool state. */
export function toolStateLabel(state: ToolPart['state']): string {
    switch (state) {
        case 'input-available':
            return 'läuft';
        case 'approval-requested':
            return 'wartet auf deine Entscheidung';
        case 'output-available':
            return 'fertig';
        case 'output-denied':
            return 'abgelehnt';
        case 'output-error':
            return 'Fehler';
    }
}

/** True when the stream part ends the turn with an error the page should show. */
export function streamError(part: StreamPart): string | null {
    return part.type === 'error' ? (part.errorText ?? 'Fehler') : null;
}
