<script lang="ts">
import type { VNode } from 'vue';
import { defineComponent, h } from 'vue';
import { parseMarkdown } from '@/lib/assistantMarkdown';
import type { InlineNode } from '@/lib/assistantMarkdown';

function inline(nodes: InlineNode[]): (VNode | string)[] {
    return nodes.map((node) => {
        switch (node.type) {
            case 'text':
                return node.text;
            case 'br':
                return h('br');
            case 'strong':
                return h(
                    'strong',
                    { class: 'font-semibold' },
                    inline(node.children),
                );
            case 'em':
                return h('em', inline(node.children));
            case 'code':
                return h(
                    'code',
                    {
                        class: 'rounded bg-muted px-1 py-0.5 font-mono text-[0.85em]',
                    },
                    node.text,
                );
            case 'link':
                return h(
                    'a',
                    {
                        href: node.href,
                        target: '_blank',
                        rel: 'noopener noreferrer',
                        class: 'underline underline-offset-2 hover:text-primary',
                    },
                    inline(node.children),
                );
        }
    });
}

/** Renders the safe Markdown subset as plain Vue elements (text is escaped by Vue, never v-html). */
export default defineComponent({
    name: 'MarkdownText',
    props: {
        text: { type: String, required: true },
        partial: { type: Boolean, default: false },
    },
    setup(props) {
        return () =>
            h(
                'div',
                { class: 'space-y-2 text-sm leading-relaxed text-foreground' },
                parseMarkdown(props.text, props.partial).map((block) =>
                    block.type === 'p'
                        ? h('p', inline(block.children))
                        : h(
                              block.type,
                              {
                                  class:
                                      block.type === 'ul'
                                          ? 'list-disc space-y-1 pl-5'
                                          : 'list-decimal space-y-1 pl-5',
                              },
                              block.items.map((item) => h('li', inline(item))),
                          ),
                ),
            );
    },
});
</script>
