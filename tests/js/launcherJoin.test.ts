// Run with: node --test tests/js/launcherJoin.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import { joinLinkNamesServer } from '../../resources/js/lib/launcherJoin.ts';

describe('joinLinkNamesServer', () => {
    it('is true for a link that carries an address or a join url', () => {
        assert.equal(
            joinLinkNamesServer(
                'lanomat://join/unreal-tournament-2004?address=203.0.113.9%3A7777',
            ),
            true,
        );
        assert.equal(
            joinLinkNamesServer(
                'lanomat://join/tiny-racer?url=steam%3A%2F%2Fconnect%2F203.0.113.5%3A27015',
            ),
            true,
        );
    });

    it('is false for a link that only installs and starts the game', () => {
        assert.equal(joinLinkNamesServer('lanomat://join/tiny-racer'), false);
        assert.equal(joinLinkNamesServer('lanomat://join/tiny-racer?'), false);
        assert.equal(
            joinLinkNamesServer('lanomat://join/tiny-racer?address='),
            false,
        );
        assert.equal(
            joinLinkNamesServer('lanomat://join/tiny-racer?other=1'),
            false,
        );
    });

    it('is false without a link', () => {
        assert.equal(joinLinkNamesServer(null), false);
        assert.equal(joinLinkNamesServer(undefined), false);
    });
});
