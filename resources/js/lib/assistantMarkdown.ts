/**
 * Tiny, safe Markdown subset for assistant answers. Model text is untrusted:
 * the parser only produces a typed node tree (no HTML strings, no raw tags,
 * no images); the renderer turns nodes into Vue elements, which escape text.
 *
 * Supported: **bold**, *italic*, `code`, [text](http(s)/relative url),
 * paragraphs, line breaks, `-`/`*`/`1.` lists, `#` headings (as bold lines).
 * Pure and IO-free: covered by `node --test tests/js/assistantMarkdown.test.ts`.
 */

export type InlineNode =
    | { type: 'text'; text: string }
    | { type: 'strong'; children: InlineNode[] }
    | { type: 'em'; children: InlineNode[] }
    | { type: 'code'; text: string }
    | { type: 'link'; href: string; children: InlineNode[] }
    | { type: 'br' };

export type Block =
    | { type: 'p'; children: InlineNode[] }
    | { type: 'ul' | 'ol'; items: InlineNode[][] };

const MAX_DEPTH = 6;

/** Only http(s) and site-relative URLs; everything else (javascript:, data:, //host) becomes plain text. */
export function safeHref(url: string): string | null {
    const trimmed = url.trim();

    if (/^https?:\/\/[^\s<>"']+$/i.test(trimmed)) {
        return trimmed;
    }

    if (/^\/(?![/\\])[^\s<>"']*$/.test(trimmed)) {
        return trimmed;
    }

    return null;
}

function pushText(out: InlineNode[], text: string): void {
    if (text === '') {
        return;
    }

    const last = out[out.length - 1];

    if (last?.type === 'text') {
        last.text += text;
    } else {
        out.push({ type: 'text', text });
    }
}

/**
 * `partial` marks text that is still streaming: an unclosed marker is then
 * hidden (and its content shown plain) instead of flashing as `**`.
 */
export function parseInline(
    source: string,
    partial = false,
    depth = 0,
): InlineNode[] {
    const out: InlineNode[] = [];
    let i = 0;

    while (i < source.length) {
        const ch = source[i];

        if (ch === '\n') {
            out.push({ type: 'br' });
            i += 1;
            continue;
        }

        if (ch === '`') {
            let run = 1;

            while (source[i + run] === '`') {
                run += 1;
            }

            const fence = '`'.repeat(run);
            const end = source.indexOf(fence, i + run);

            if (end > i + run) {
                out.push({
                    type: 'code',
                    text: source.slice(i + run, end).replace(/\n/g, ' '),
                });
                i = end + run;
            } else {
                // Unclosed: show the rest verbatim, minus the marker while streaming.
                if (partial) {
                    pushText(out, source.slice(i + run));
                    break;
                }

                pushText(out, fence);
                i += run;
            }

            continue;
        }

        if (
            (ch === '*' && source[i + 1] === '*') ||
            (ch === '*' &&
                depth < MAX_DEPTH &&
                !/\s/.test(source[i + 1] ?? ' '))
        ) {
            const strong = source[i + 1] === '*';
            const marker = strong ? '**' : '*';
            let end = -1;
            let from = i + marker.length;

            while (from < source.length) {
                const candidate = source.indexOf(marker, from);

                // A single `*` must not be the first half of a `**`.
                if (
                    candidate === -1 ||
                    strong ||
                    source[candidate + 1] !== '*'
                ) {
                    end = candidate;
                    break;
                }

                from = candidate + 2;
            }

            if (end > i + marker.length && depth < MAX_DEPTH) {
                out.push({
                    type: strong ? 'strong' : 'em',
                    children: parseInline(
                        source.slice(i + marker.length, end),
                        false,
                        depth + 1,
                    ),
                });
                i = end + marker.length;
                continue;
            }

            if (partial && end === -1) {
                // Still open: format the content now, without the marker.
                out.push({
                    type: strong ? 'strong' : 'em',
                    children: parseInline(
                        source.slice(i + marker.length),
                        true,
                        depth + 1,
                    ),
                });
                break;
            }

            pushText(out, marker);
            i += marker.length;
            continue;
        }

        if (ch === '[') {
            const match = /^\[([^\]\n]{1,300})\]\(([^()\s]{1,2000})\)/.exec(
                source.slice(i, i + 2400),
            );

            if (match) {
                const href = safeHref(match[2]);
                const children = parseInline(match[1], false, depth + 1);

                if (href) {
                    out.push({ type: 'link', href, children });
                } else {
                    out.push(...children);
                }

                i += match[0].length;
                continue;
            }
        }

        // Plain run up to the next special character.
        let j = i + 1;

        while (j < source.length && !'\n`*['.includes(source[j])) {
            j += 1;
        }

        pushText(out, source.slice(i, j));
        i = j;
    }

    return out;
}

const bullet = /^\s{0,3}[-*•]\s+(.*)$/;
const numbered = /^\s{0,3}\d{1,3}[.)]\s+(.*)$/;
const heading = /^\s{0,3}#{1,6}\s+(.*)$/;

export function parseMarkdown(source: string, partial = false): Block[] {
    const lines = source.replace(/\r\n?/g, '\n').split('\n');
    const blocks: Block[] = [];
    let paragraph: string[] = [];
    let list: { type: 'ul' | 'ol'; items: string[] } | null = null;

    const flushParagraph = (last: boolean): void => {
        if (paragraph.length > 0) {
            blocks.push({
                type: 'p',
                children: parseInline(paragraph.join('\n'), partial && last),
            });
            paragraph = [];
        }
    };
    const flushList = (last: boolean): void => {
        if (list) {
            const items = list.items;
            blocks.push({
                type: list.type,
                items: items.map((item, n) =>
                    parseInline(
                        item,
                        partial && last && n === items.length - 1,
                    ),
                ),
            });
            list = null;
        }
    };

    lines.forEach((line, index) => {
        const last = index === lines.length - 1;
        const bulletMatch = bullet.exec(line);
        const numberMatch = numbered.exec(line);
        const headingMatch = heading.exec(line);

        if (line.trim() === '') {
            flushParagraph(false);
            flushList(false);
        } else if (bulletMatch || numberMatch) {
            flushParagraph(false);
            const type = bulletMatch ? 'ul' : 'ol';

            if (list && list.type !== type) {
                flushList(false);
            }

            list ??= { type, items: [] };
            list.items.push((bulletMatch ?? numberMatch)![1]);
        } else if (headingMatch) {
            flushParagraph(false);
            flushList(false);
            blocks.push({
                type: 'p',
                children: [
                    {
                        type: 'strong',
                        children: parseInline(headingMatch[1], partial && last),
                    },
                ],
            });
        } else if (list && /^\s{2,}\S/.test(line)) {
            // Continuation line of the previous list item.
            list.items[list.items.length - 1] += `\n${line.trim()}`;
        } else {
            flushList(false);
            paragraph.push(line);
        }

        if (last) {
            flushParagraph(true);
            flushList(true);
        }
    });

    return blocks;
}
