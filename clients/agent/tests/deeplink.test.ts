import { describe, expect, it } from 'vitest';
import { deepLinkCommand, parseDeepLink } from '../src/deeplink.js';

describe('LANoMAT deep links', () => {
    it('parses install links', () => {
        expect(parseDeepLink('lanomat://install/ut2004')).toMatchObject({
            action: 'install',
            slug: 'ut2004',
        });
    });

    it('parses launch and play links to the same action', () => {
        expect(parseDeepLink('lanomat://launch/ut2004').action).toBe('launch');
        expect(parseDeepLink('lanomat://play/ut2004')).toMatchObject({
            action: 'launch',
            slug: 'ut2004',
        });
        expect(deepLinkCommand(parseDeepLink('lanomat://play/ut2004'))).toBe(
            'lanomat-agent launch ut2004',
        );
    });

    it('accepts an empty authority form and URL-encoded slugs', () => {
        expect(parseDeepLink('lanomat:///install/ut2004').action).toBe('install');
        expect(parseDeepLink('lanomat://launch/ut%32%30%30%34').slug).toBe('ut2004');
    });

    it('rejects foreign schemes, unknown actions and bad slugs', () => {
        expect(() => parseDeepLink('https://lanomat.local/install/ut2004')).toThrow('Not a LANoMAT link');
        expect(() => parseDeepLink('lanomat://watch/ut2004')).toThrow('Unknown LANoMAT action');
        expect(() => parseDeepLink('lanomat://install/')).toThrow('Invalid recipe slug');
        expect(() => parseDeepLink('lanomat://install/%2E%2E')).toThrow('Invalid recipe slug');
        expect(() => parseDeepLink('not a url')).toThrow('Invalid LANoMAT link');
    });

    it('rejects encoded path traversal and separators', () => {
        expect(() => parseDeepLink('lanomat://install/%2e%2e%2fetc')).toThrow('path traversal');
        expect(() => parseDeepLink('lanomat://install/a%2Fb')).toThrow('path traversal');
        expect(() => parseDeepLink('lanomat://launch/a%5Cb')).toThrow('path traversal');
        expect(() => parseDeepLink('lanomat://install/ut%20004')).toThrow('whitespace');
    });
});
