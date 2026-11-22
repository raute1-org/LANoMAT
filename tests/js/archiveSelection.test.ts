// Run with: node --test tests/js/archiveSelection.test.ts
import assert from 'node:assert/strict';
import { describe, it } from 'node:test';
import type { ArchiveSelectionFile } from '../../resources/js/lib/archiveSelection.ts';
import { selectUploadedArchive } from '../../resources/js/lib/archiveSelection.ts';

const archive = (
    id: number,
    name: string,
    usable = true,
): ArchiveSelectionFile => ({ id, name, usable });

const existing = [archive(7, 'older.zip'), archive(9, 'newer.tar.bz2')];

describe('selectUploadedArchive', () => {
    it('selects the archive that just arrived when nothing is selected', () => {
        const files = [archive(12, 'MeinSpiel.zip'), ...existing];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: 'MeinSpiel.zip',
                current: null,
                manual: false,
            }),
            12,
        );
    });

    it('selects by name when several files arrived at once', () => {
        const files = [
            archive(13, 'Anderes.zip'),
            archive(12, 'MeinSpiel.zip'),
            ...existing,
        ];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: 'MeinSpiel.zip',
                current: null,
                manual: false,
            }),
            12,
        );
    });

    it('falls back to the newest new archive without a name (resumable mode)', () => {
        const files = [
            archive(13, 'Anderes.zip'),
            archive(12, 'MeinSpiel.zip'),
        ];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: null,
                current: null,
                manual: false,
            }),
            13,
        );
    });

    it('matches a picked path through its file name, not through the list order', () => {
        // Two files arrived; the newest (first) one is NOT the one picked here.
        const files = [
            archive(13, 'Anderes Spiel.zip'),
            archive(12, 'Mein Spiel.zip'),
            ...existing,
        ];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: 'C:\\Spiele\\Mein Spiel.zip',
                current: null,
                manual: false,
            }),
            12,
        );
    });

    it('never overrides a hand-picked selection', () => {
        const files = [archive(12, 'MeinSpiel.zip'), ...existing];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: 'MeinSpiel.zip',
                current: 7,
                manual: true,
            }),
            7,
        );
    });

    it('keeps the selection when the new file is not a usable archive', () => {
        const files = [archive(12, 'spiel.iso', false), ...existing];

        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files,
                uploadedName: 'spiel.iso',
                current: 9,
                manual: false,
            }),
            9,
        );
    });

    it('selects nothing when only an unusable archive arrived', () => {
        const files = [archive(12, 'spiel.iso', false)];

        assert.equal(
            selectUploadedArchive({
                previousIds: [],
                files,
                uploadedName: null,
                current: null,
                manual: false,
            }),
            null,
        );
    });

    it('changes nothing when the list did not grow', () => {
        assert.equal(
            selectUploadedArchive({
                previousIds: existing.map((file) => file.id),
                files: existing,
                uploadedName: 'MeinSpiel.zip',
                current: null,
                manual: false,
            }),
            null,
        );
    });
});
