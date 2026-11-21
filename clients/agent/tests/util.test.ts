import { describe, expect, it } from 'vitest';
import { sanitizeName, sanitizeSlug, validateRecipeSlug } from '../src/util.js';

describe('validateRecipeSlug', () => {
    it('accepts normal recipe slugs', () => {
        for (const slug of ['ut2004', 'counter-strike_1.6', 'RidgeRacer4', 'a', 'x'.repeat(64)]) {
            expect(validateRecipeSlug(slug)).toBe(slug);
        }
    });

    it('rejects path traversal', () => {
        for (const slug of ['../etc', '..', '.', 'a/b', 'a\\b', '/etc/passwd', 'foo/..']) {
            expect(() => validateRecipeSlug(slug)).toThrow(/path traversal|Invalid recipe slug/);
        }
    });

    it('rejects encoded separators, whitespace, control characters and bad shapes', () => {
        expect(() => validateRecipeSlug('a%2Fb')).toThrow('percent escapes');
        expect(() => validateRecipeSlug('.')).toThrow('path traversal');
        expect(() => validateRecipeSlug(' ut2004')).toThrow('whitespace');
        expect(() => validateRecipeSlug('ut 2004')).toThrow('whitespace');
        expect(() => validateRecipeSlug('a\0b')).toThrow('percent escapes and control characters');
        expect(() => validateRecipeSlug('')).toThrow('the slug is empty');
        expect(() => validateRecipeSlug('-ut2004')).toThrow('starting with a letter or digit');
        expect(() => validateRecipeSlug('.hidden')).toThrow('starting with a letter or digit');
        expect(() => validateRecipeSlug('x'.repeat(65))).toThrow('at most 64 characters');
        expect(() => validateRecipeSlug('ut2004!')).toThrow('use letters, digits');
    });

    it('keeps sanitizeSlug as the normalising variant', () => {
        expect(sanitizeSlug(' UT 2004 ')).toBe('ut-2004');
        expect(sanitizeSlug('ut2004')).toBe('ut2004');
        expect(sanitizeName('a/b')).toBe('a-b');
    });
});
