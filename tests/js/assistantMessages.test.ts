// Run with: node --test tests/js/assistantMessages.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    applyStreamPart,
    toolStateLabel,
    withUserMessage,
} from '../../resources/js/lib/assistantMessages.ts';
import { parseSseChunk } from '../../resources/js/lib/assistantStream.ts';
import type {
    StreamPart,
    UiMessage,
} from '../../resources/js/types/assistant.ts';

function run(parts: StreamPart[], start: UiMessage[] = []): UiMessage[] {
    return parts.reduce(
        (messages, part) => applyStreamPart(messages, part),
        start,
    );
}

describe('applyStreamPart', () => {
    it('builds an assistant message from text deltas', () => {
        const messages = run([
            { type: 'start', messageId: 'm1' },
            { type: 'text-start', id: 't1' },
            { type: 'text-delta', id: 't1', delta: 'Hallo' },
            { type: 'text-delta', id: 't1', delta: ' Orga' },
        ]);

        assert.equal(messages.length, 1);
        assert.deepEqual(messages[0].parts, [
            { type: 'text', id: 't1', text: 'Hallo Orga' },
        ]);
    });

    it('tracks a tool call through approval and output', () => {
        const messages = run([
            { type: 'start', messageId: 'm1' },
            {
                type: 'tool-input-available',
                toolCallId: 'c1',
                toolName: 'tournament_create',
                input: { name: 'x' },
            },
            { type: 'tool-approval-request', toolCallId: 'c1' },
        ]);
        const part = messages[0].parts[0];

        assert.equal(part.type, 'tool-tournament_create');
        assert.equal((part as { state: string }).state, 'approval-requested');

        const done = applyStreamPart(messages, {
            type: 'tool-output-available',
            toolCallId: 'c1',
            output: '{"ok":true}',
        });
        assert.equal(
            (done[0].parts[0] as { state: string }).state,
            'output-available',
        );
    });

    it('marks denied and failed tools and ignores unknown parts', () => {
        const base = run([
            { type: 'start', messageId: 'm1' },
            {
                type: 'tool-input-available',
                toolCallId: 'c1',
                toolName: 'a',
                input: {},
            },
        ]);

        assert.equal(
            (
                applyStreamPart(base, {
                    type: 'tool-output-denied',
                    toolCallId: 'c1',
                })[0].parts[0] as { state: string }
            ).state,
            'output-denied',
        );
        assert.equal(
            (
                applyStreamPart(base, {
                    type: 'tool-output-error',
                    toolCallId: 'c1',
                    errorText: 'x',
                })[0].parts[0] as { state: string }
            ).state,
            'output-error',
        );
        assert.equal(applyStreamPart(base, { type: 'finish-step' }), base);
    });

    it('does not mutate its input', () => {
        const start = withUserMessage([], 'Hi');
        const snapshot = JSON.stringify(start);
        applyStreamPart(start, { type: 'start', messageId: 'm1' });

        assert.equal(JSON.stringify(start), snapshot);
    });

    it('labels every tool state in German', () => {
        assert.equal(toolStateLabel('output-available'), 'fertig');
        assert.equal(
            toolStateLabel('approval-requested'),
            'wartet auf deine Entscheidung',
        );
    });
});

describe('parseSseChunk', () => {
    it('returns complete events and keeps the unfinished rest', () => {
        const { parts, rest, done } = parseSseChunk(
            'data: {"type":"start","messageId":"a"}\n\ndata: {"type":"text-del',
        );

        assert.equal(parts.length, 1);
        assert.equal(rest, 'data: {"type":"text-del');
        assert.equal(done, false);
    });

    it('recognizes the terminator and skips malformed payloads', () => {
        const { parts, done } = parseSseChunk(
            'data: nope\n\ndata: {"type":"finish"}\n\ndata: [DONE]\n\n',
        );

        assert.deepEqual(parts, [{ type: 'finish' }]);
        assert.equal(done, true);
    });
});
