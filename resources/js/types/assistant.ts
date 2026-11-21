/** Wire types of the orga assistant (Vercel UI message stream + server props). */

export type ToolState =
    | 'input-available'
    | 'approval-requested'
    | 'output-available'
    | 'output-denied'
    | 'output-error';

export type TextPart = { type: 'text'; text: string; id?: string };
export type ReasoningPart = { type: 'reasoning'; text: string };
export type ToolPart = {
    /** `tool-<tool name>`, e.g. `tool-events_list`. */
    type: `tool-${string}`;
    toolCallId: string;
    state: ToolState;
    input?: Record<string, unknown>;
    output?: unknown;
    errorText?: string;
};
export type UiPart = TextPart | ReasoningPart | ToolPart;

export type UiMessage = {
    id: string;
    role: 'user' | 'assistant';
    parts: UiPart[];
};

/** One part of the server-sent stream (the subset the page reacts to). */
export type StreamPart = {
    type: string;
    id?: string;
    messageId?: string;
    delta?: string;
    toolCallId?: string;
    toolName?: string;
    input?: Record<string, unknown>;
    output?: unknown;
    errorText?: string;
};

export type PreviewRow = { label: string; value: string; mono: boolean };

export type ApprovalPreview = {
    title: string;
    rows: PreviewRow[];
    danger: boolean;
    needsSecret: boolean;
};

export type PendingCard = {
    callId: string;
    tool: string;
    preview: ApprovalPreview;
    status: string;
    decidable: boolean;
};

export type ConversationSummary = {
    id: string;
    title: string;
    updated_at: string;
};

export type AssistantProps = {
    conversations: ConversationSummary[];
    conversationId: string | null;
    messages: UiMessage[];
    pending: PendingCard[];
    provider: { name: string };
};

export type Decision = 'approve' | 'reject';
