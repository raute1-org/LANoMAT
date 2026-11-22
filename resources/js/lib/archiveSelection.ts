/**
 * Which archive the share page selects after an upload on that page.
 *
 * On arrival nothing is preselected unless `?file=` names a usable archive.
 * That stays the rule; but a participant who uploads an archive *here* should
 * not have to find it in the list and click it again. The decision is pure so
 * it can be unit-tested (`tests/js/archiveSelection.test.ts`):
 *
 * - a selection the participant made by hand always wins (before or while the
 *   upload ran);
 * - otherwise the file that arrived with this upload is selected — by name
 *   when the upload mode knows it, else the newest new usable archive, since
 *   the server lists newest first;
 * - a new archive the server marked `usable: false` changes nothing, so the
 *   "Es fehlt noch: ein Archiv." line keeps saying why.
 */
export interface ArchiveSelectionFile {
    id: number;
    name: string;
    /** False when the server cannot use the file as a share archive. */
    usable: boolean;
}

export interface UploadedArchiveInput {
    /** The ids the server listed before the upload finished. */
    previousIds: readonly number[];
    /** The list the server sent after the upload finished (newest first). */
    files: readonly ArchiveSelectionFile[];
    /** The name the participant picked, when the upload mode reports it. */
    uploadedName?: string | null;
    /** The selection before the upload finished. */
    current: number | null;
    /** True when the participant picked `current` by hand. */
    manual: boolean;
}

/** Last segment of a path, so a "C:\games\x.zip" pick still matches "x.zip". */
function baseName(name: string): string {
    return name.split(/[\\/]/).pop() ?? name;
}

export function selectUploadedArchive(
    input: UploadedArchiveInput,
): number | null {
    if (input.manual) {
        return input.current;
    }

    const known = new Set(input.previousIds);
    const added = input.files.filter(
        (file) => !known.has(file.id) && file.usable,
    );

    if (added.length === 0) {
        return input.current;
    }

    const wanted = input.uploadedName?.trim().toLowerCase();

    if (wanted !== undefined && wanted !== '') {
        const exact = added.find(
            (file) => file.name.trim().toLowerCase() === wanted,
        );

        if (exact !== undefined) {
            return exact.id;
        }

        const sameName = added.find(
            (file) =>
                baseName(file.name).trim().toLowerCase() ===
                baseName(wanted).trim(),
        );

        if (sameName !== undefined) {
            return sameName.id;
        }
    }

    return added[0].id;
}
