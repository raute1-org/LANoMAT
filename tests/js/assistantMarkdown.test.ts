// Run with: node --test tests/js/assistantMarkdown.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import {
    parseInline,
    parseMarkdown,
    safeHref,
} from '../../resources/js/lib/assistantMarkdown.ts';
import type { InlineNode } from '../../resources/js/lib/assistantMarkdown.ts';

/** Flattens a node tree to a compact string for assertions. */
function show(nodes: InlineNode[]): string {
    return nodes
        .map((n) => {
            switch (n.type) {
                case 'text':
                    return n.text;
                case 'br':
                    return '<br>';
                case 'code':
                    return `[code:${n.text}]`;
                case 'strong':
                    return `<b>${show(n.children)}</b>`;
                case 'em':
                    return `<i>${show(n.children)}</i>`;
                case 'link':
                    return `<a ${n.href}>${show(n.children)}</a>`;
            }
        })
        .join('');
}

describe('parseInline', () => {
    it('formats bold, italic and code', () => {
        assert.equal(
            show(parseInline('**28.11.2026** und *kursiv* und `id_1`')),
            '<b>28.11.2026</b> und <i>kursiv</i> und [code:id_1]',
        );
    });

    it('nests italic in bold', () => {
        assert.equal(show(parseInline('**a *b* c**')), '<b>a <i>b</i> c</b>');
    });

    it('keeps a lone asterisk and arithmetic literal', () => {
        assert.equal(show(parseInline('2 * 3 = 6')), '2 * 3 = 6');
    });

    it('shows an unclosed marker literally in saved text', () => {
        assert.equal(show(parseInline('**x')), '**x');
        assert.equal(show(parseInline('`x')), '`x');
    });

    it('hides an unclosed marker while streaming', () => {
        assert.equal(show(parseInline('Hallo **fe', true)), 'Hallo <b>fe</b>');
        assert.equal(show(parseInline('Hallo *fe', true)), 'Hallo <i>fe</i>');
        assert.equal(show(parseInline('Hallo `co', true)), 'Hallo co');
        assert.equal(show(parseInline('Hallo **', true)), 'Hallo <b></b>');
    });

    it('handles backticks inside backticks via longer fences', () => {
        assert.equal(show(parseInline('`` a`b ``')), '[code: a`b ]');
    });

    it('never produces raw HTML: tags stay text', () => {
        const nodes = parseInline(
            '<script>alert(1)</script><img src=x onerror=alert(1)>',
        );

        assert.ok(nodes.every((n) => n.type === 'text'));
        assert.equal(
            show(nodes),
            '<script>alert(1)</script><img src=x onerror=alert(1)>',
        );
    });

    it('drops dangerous link targets but keeps the label', () => {
        assert.ok(
            !show(parseInline('[klick](javascript:alert)')).includes('<a'),
        );
        assert.ok(
            !show(parseInline('[klick](data:text/html,x)')).includes('<a'),
        );
        assert.ok(!show(parseInline('[klick](//evil.example)')).includes('<a'));
        assert.equal(show(parseInline('[klick](javascript:alert)')), 'klick');
    });

    it('allows https and relative links only', () => {
        assert.equal(
            safeHref('https://lan.example/a?b=1'),
            'https://lan.example/a?b=1',
        );
        assert.equal(safeHref('/orga/events'), '/orga/events');
        assert.equal(safeHref('JaVaScRiPt:alert(1)'), null);
        assert.equal(safeHref('http://a b'), null);
        assert.equal(
            show(parseInline('[Event](/events/x)')),
            '<a /events/x>Event</a>',
        );
    });

    it('survives very long and pathological lines', () => {
        const started = Date.now();

        parseInline('*'.repeat(50000));
        parseInline('**a'.repeat(5000));
        parseInline('['.repeat(20000));
        parseInline('`'.repeat(20000));
        parseInline('x'.repeat(200000));
        parseInline('*a '.repeat(8000), true);

        assert.ok(Date.now() - started < 4000);
    });
});

describe('parseMarkdown', () => {
    it('splits paragraphs and keeps single newlines as breaks', () => {
        const blocks = parseMarkdown('Eins\nzwei\n\nDrei');

        assert.equal(blocks.length, 2);
        assert.equal(
            show((blocks[0] as { children: InlineNode[] }).children),
            'Eins<br>zwei',
        );
    });

    it('builds bullet and numbered lists', () => {
        const blocks = parseMarkdown(
            'Optionen:\n- **A**\n- B\n\n1. eins\n2. zwei',
        );

        assert.deepEqual(
            blocks.map((b) => b.type),
            ['p', 'ul', 'ol'],
        );
        assert.equal((blocks[1] as { items: unknown[] }).items.length, 2);
        assert.equal((blocks[2] as { items: unknown[] }).items.length, 2);
    });

    it('renders headings as bold lines', () => {
        const [block] = parseMarkdown('## Titel');

        assert.equal(
            show((block as { children: InlineNode[] }).children),
            '<b>Titel</b>',
        );
    });

    it('formats only the still-open last line while streaming', () => {
        const [block] = parseMarkdown('Fertig **a** und **fe', true);

        assert.equal(
            show((block as { children: InlineNode[] }).children),
            'Fertig <b>a</b> und <b>fe</b>',
        );
    });

    it('returns no blocks for empty text and tolerates CRLF', () => {
        assert.deepEqual(parseMarkdown(''), []);
        assert.equal(parseMarkdown('a\r\n\r\nb').length, 2);
    });
});
