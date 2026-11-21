import type { InjectionKey } from 'vue';
import { inject, provide } from 'vue';

export type NowLabelFn = (
    key: string,
    replacements?: Record<string, string | number>,
) => string;

const key: InjectionKey<NowLabelFn> = Symbol('now-labels');

/**
 * Called once by the Now page: exposes `trans('now')` to all panels and returns
 * the same translator. (A component cannot `inject` what it `provide`s itself,
 * so the page must use the returned function.)
 */
export function provideNowLabels(
    labels: () => Record<string, string>,
): NowLabelFn {
    const translate: NowLabelFn = (name, replacements = {}) => {
        let text = labels()[name] ?? name;

        for (const [k, v] of Object.entries(replacements)) {
            text = text.replaceAll(`:${k}`, String(v));
        }

        return text;
    };

    provide(key, translate);

    return translate;
}

export function useNowLabels(): NowLabelFn {
    return inject(key, (name: string) => name);
}
