// A dependency-free QR encoder (byte mode, versions 1–10, ECC M with an L
// fallback) for the arcade sign-in. It only draws a URL the server handed us;
// it never fetches anything and uses no DOM beyond the passed container.
//
// The bit layout follows ISO/IEC 18004: finder/timing/alignment patterns,
// Reed–Solomon ECC per block, the eight data masks with the standard penalty
// score, format bits and (for version 7+) version bits.

const FORMAT_ECC = { L: 1, M: 0 };

// [totalCodewords, dataCodewords] per block group, versions 1–10.
const RS_BLOCKS = {
    L: [
        [[1, 26, 19]],
        [[1, 44, 34]],
        [[1, 70, 55]],
        [[1, 100, 80]],
        [[1, 134, 108]],
        [[2, 86, 68]],
        [[2, 98, 78]],
        [[2, 121, 97]],
        [[2, 146, 116]],
        [[2, 86, 68], [2, 87, 69]],
    ],
    M: [
        [[1, 26, 16]],
        [[1, 44, 28]],
        [[1, 70, 44]],
        [[2, 50, 32]],
        [[2, 67, 43]],
        [[4, 43, 27]],
        [[4, 49, 31]],
        [[2, 60, 38], [2, 61, 39]],
        [[3, 58, 36], [2, 59, 37]],
        [[4, 69, 43], [1, 70, 44]],
    ],
};

const ALIGNMENT = [
    [],
    [6, 18],
    [6, 22],
    [6, 26],
    [6, 30],
    [6, 34],
    [6, 22, 38],
    [6, 24, 42],
    [6, 26, 46],
    [6, 28, 50],
];

// GF(256) tables for the 0x11d primitive polynomial.
const GF_EXP = new Uint8Array(512);
const GF_LOG = new Uint8Array(256);

(() => {
    let value = 1;

    for (let i = 0; i < 255; i += 1) {
        GF_EXP[i] = value;
        GF_LOG[value] = i;
        value <<= 1;

        if (value & 0x100) {
            value ^= 0x11d;
        }
    }

    for (let i = 255; i < 512; i += 1) {
        GF_EXP[i] = GF_EXP[i - 255];
    }
})();

function gfMul(a, b) {
    return a === 0 || b === 0 ? 0 : GF_EXP[GF_LOG[a] + GF_LOG[b]];
}

function generator(degree) {
    let poly = [1];

    for (let i = 0; i < degree; i += 1) {
        const next = new Array(poly.length + 1).fill(0);

        for (let j = 0; j < poly.length; j += 1) {
            next[j] ^= poly[j];
            next[j + 1] ^= gfMul(poly[j], GF_EXP[i]);
        }

        poly = next;
    }

    return poly;
}

function reedSolomon(data, degree) {
    const gen = generator(degree);
    const remainder = new Array(degree).fill(0);

    for (const byte of data) {
        const factor = byte ^ remainder[0];

        remainder.shift();
        remainder.push(0);

        if (factor !== 0) {
            for (let i = 0; i < degree; i += 1) {
                remainder[i] ^= gfMul(gen[i + 1], factor);
            }
        }
    }

    return remainder;
}

function dataCapacity(version, ecc) {
    return RS_BLOCKS[ecc][version - 1].reduce(
        (sum, [count, , data]) => sum + count * data,
        0,
    );
}

function utf8(text) {
    return Array.from(new TextEncoder().encode(text));
}

function buildCodewords(bytes, version, ecc) {
    const capacity = dataCapacity(version, ecc) * 8;
    const lengthBits = version >= 10 ? 16 : 8;
    const bits = [];

    const push = (value, count) => {
        for (let i = count - 1; i >= 0; i -= 1) {
            bits.push((value >> i) & 1);
        }
    };

    push(0b0100, 4);
    push(bytes.length, lengthBits);

    for (const byte of bytes) {
        push(byte, 8);
    }

    // Terminator, byte alignment, then the two standard pad codewords.
    push(0, Math.min(4, capacity - bits.length));

    while (bits.length % 8 !== 0) {
        bits.push(0);
    }

    const codewords = [];

    for (let i = 0; i < bits.length; i += 8) {
        let value = 0;

        for (let j = 0; j < 8; j += 1) {
            value = (value << 1) | bits[i + j];
        }

        codewords.push(value);
    }

    for (let pad = 0; codewords.length < capacity / 8; pad += 1) {
        codewords.push(pad % 2 === 0 ? 0xec : 0x11);
    }

    return codewords;
}

function interleave(codewords, version, ecc) {
    const groups = [];

    for (const [count, total, data] of RS_BLOCKS[ecc][version - 1]) {
        for (let i = 0; i < count; i += 1) {
            groups.push({ total, data });
        }
    }

    const blocks = [];
    let offset = 0;

    for (const group of groups) {
        const data = codewords.slice(offset, offset + group.data);

        offset += group.data;
        blocks.push({ data, ecc: reedSolomon(data, group.total - group.data) });
    }

    const result = [];
    const maxData = Math.max(...blocks.map((block) => block.data.length));

    for (let i = 0; i < maxData; i += 1) {
        for (const block of blocks) {
            if (i < block.data.length) {
                result.push(block.data[i]);
            }
        }
    }

    const eccLength = blocks[0].ecc.length;

    for (let i = 0; i < eccLength; i += 1) {
        for (const block of blocks) {
            result.push(block.ecc[i]);
        }
    }

    return result;
}

function bchDigit(value) {
    let digit = 0;

    while (value !== 0) {
        digit += 1;
        value >>>= 1;
    }

    return digit;
}

function bchTypeInfo(data) {
    let value = data << 10;

    while (bchDigit(value) - bchDigit(0x537) >= 0) {
        value ^= 0x537 << (bchDigit(value) - bchDigit(0x537));
    }

    return ((data << 10) | value) ^ 0x5412;
}

function bchTypeNumber(data) {
    let value = data << 12;

    while (bchDigit(value) - bchDigit(0x1f25) >= 0) {
        value ^= 0x1f25 << (bchDigit(value) - bchDigit(0x1f25));
    }

    return (data << 12) | value;
}

function buildBase(version) {
    const size = 17 + 4 * version;
    const modules = Array.from({ length: size }, () => new Array(size).fill(false));
    const reserved = Array.from({ length: size }, () => new Array(size).fill(false));
    const set = (row, col, dark) => {
        if (row < 0 || col < 0 || row >= size || col >= size) {
            return;
        }

        modules[row][col] = dark;
        reserved[row][col] = true;
    };

    const finder = (top, left) => {
        for (let row = -1; row <= 7; row += 1) {
            for (let col = -1; col <= 7; col += 1) {
                const r = top + row;
                const c = left + col;
                const dark =
                    row >= 0 &&
                    row <= 6 &&
                    col >= 0 &&
                    col <= 6 &&
                    (row === 0 ||
                        row === 6 ||
                        col === 0 ||
                        col === 6 ||
                        (row >= 2 && row <= 4 && col >= 2 && col <= 4));

                if (r >= 0 && c >= 0 && r < size && c < size) {
                    set(r, c, dark);
                }
            }
        }
    };

    finder(0, 0);
    finder(0, size - 7);
    finder(size - 7, 0);

    for (let i = 8; i < size - 8; i += 1) {
        set(6, i, i % 2 === 0);
        set(i, 6, i % 2 === 0);
    }

    for (const row of ALIGNMENT[version - 1]) {
        for (const col of ALIGNMENT[version - 1]) {
            if (
                (row === 6 && col === 6) ||
                (row === 6 && col === size - 7) ||
                (row === size - 7 && col === 6)
            ) {
                continue;
            }

            for (let dr = -2; dr <= 2; dr += 1) {
                for (let dc = -2; dc <= 2; dc += 1) {
                    set(
                        row + dr,
                        col + dc,
                        Math.abs(dr) === 2 ||
                            Math.abs(dc) === 2 ||
                            (dr === 0 && dc === 0),
                    );
                }
            }
        }
    }

    // Format areas and the fixed dark module.
    for (let i = 0; i < 9; i += 1) {
        if (i !== 6) {
            set(8, i, false);
            set(i, 8, false);
        }
    }

    for (let i = 0; i < 8; i += 1) {
        set(8, size - 1 - i, false);
        set(size - 1 - i, 8, false);
    }

    // Version areas (7+).
    if (version >= 7) {
        for (let i = 0; i < 6; i += 1) {
            for (let j = 0; j < 3; j += 1) {
                set(i, size - 11 + j, false);
                set(size - 11 + j, i, false);
            }
        }
    }

    set(size - 8, 8, true);

    return { size, modules, reserved };
}

function placeData(base, codewordBits) {
    const { size, modules, reserved } = base;
    const positions = [];
    let upward = true;

    for (let col = size - 1; col > 0; col -= 2) {
        if (col === 6) {
            col -= 1;
        }

        for (let step = 0; step < size; step += 1) {
            const row = upward ? size - 1 - step : step;

            for (const column of [col, col - 1]) {
                if (!reserved[row][column]) {
                    positions.push([row, column]);
                }
            }
        }

        upward = !upward;
    }

    let bit = 0;

    for (const [row, col] of positions) {
        modules[row][col] = bit < codewordBits.length ? codewordBits[bit] === 1 : false;
        bit += 1;
    }

    return positions;
}

function maskBit(mask, row, col) {
    switch (mask) {
        case 0:
            return (row + col) % 2 === 0;
        case 1:
            return row % 2 === 0;
        case 2:
            return col % 3 === 0;
        case 3:
            return (row + col) % 3 === 0;
        case 4:
            return (Math.floor(row / 2) + Math.floor(col / 3)) % 2 === 0;
        case 5:
            return ((row * col) % 2) + ((row * col) % 3) === 0;
        case 6:
            return (((row * col) % 2) + ((row * col) % 3)) % 2 === 0;
        default:
            return (((row + col) % 2) + ((row * col) % 3)) % 2 === 0;
    }
}

function placeFormat(modules, size, ecc, mask) {
    const bits = bchTypeInfo((FORMAT_ECC[ecc] << 3) | mask);

    for (let i = 0; i < 15; i += 1) {
        const dark = ((bits >> i) & 1) === 1;

        if (i < 6) {
            modules[i][8] = dark;
        } else if (i < 8) {
            modules[i + 1][8] = dark;
        } else {
            modules[size - 15 + i][8] = dark;
        }
    }

    for (let i = 0; i < 15; i += 1) {
        const dark = ((bits >> i) & 1) === 1;

        if (i < 8) {
            modules[8][size - 1 - i] = dark;
        } else if (i < 9) {
            modules[8][15 - i] = dark;
        } else {
            modules[8][15 - i - 1] = dark;
        }
    }

    modules[size - 8][8] = true;
}

function placeVersion(modules, size, version) {
    if (version < 7) {
        return;
    }

    const bits = bchTypeNumber(version);

    for (let i = 0; i < 18; i += 1) {
        const dark = ((bits >> i) & 1) === 1;

        modules[Math.floor(i / 3)][(i % 3) + size - 11] = dark;
        modules[(i % 3) + size - 11][Math.floor(i / 3)] = dark;
    }
}

function penalty(modules, size) {
    let score = 0;

    // Rule 1: runs of five or more.
    for (const line of [...modules, ...Array.from({ length: size }, (_, col) => modules.map((row) => row[col]))]) {
        let run = 1;

        for (let i = 1; i < size; i += 1) {
            if (line[i] === line[i - 1]) {
                run += 1;
            } else {
                if (run >= 5) {
                    score += 3 + (run - 5);
                }

                run = 1;
            }
        }

        if (run >= 5) {
            score += 3 + (run - 5);
        }
    }

    // Rule 2: 2x2 blocks of one colour.
    for (let row = 0; row < size - 1; row += 1) {
        for (let col = 0; col < size - 1; col += 1) {
            const value = modules[row][col];

            if (
                modules[row + 1][col] === value &&
                modules[row][col + 1] === value &&
                modules[row + 1][col + 1] === value
            ) {
                score += 3;
            }
        }
    }

    // Rule 3: the finder-like 1:1:3:1:1 pattern with four quiet modules.
    const pattern = [true, false, true, true, true, false, true];

    for (let row = 0; row < size; row += 1) {
        for (let col = 0; col <= size - 7; col += 1) {
            let found = true;

            for (let i = 0; i < 7; i += 1) {
                if (modules[row][col + i] !== pattern[i]) {
                    found = false;
                    break;
                }
            }

            if (found) {
                const quiet = [col - 4, col + 7].some((start) => {
                    if (start < 0 || start + 4 > size) {
                        return false;
                    }

                    return [0, 1, 2, 3].every((i) => modules[row][start + i] === false);
                });

                if (quiet) {
                    score += 40;
                }
            }
        }
    }

    for (let col = 0; col < size; col += 1) {
        for (let row = 0; row <= size - 7; row += 1) {
            let found = true;

            for (let i = 0; i < 7; i += 1) {
                if (modules[row + i][col] !== pattern[i]) {
                    found = false;
                    break;
                }
            }

            if (found) {
                const quiet = [row - 4, row + 7].some((start) => {
                    if (start < 0 || start + 4 > size) {
                        return false;
                    }

                    return [0, 1, 2, 3].every((i) => modules[start + i][col] === false);
                });

                if (quiet) {
                    score += 40;
                }
            }
        }
    }

    // Rule 4: distance from a 50% dark ratio.
    let dark = 0;

    for (const row of modules) {
        for (const value of row) {
            if (value) {
                dark += 1;
            }
        }
    }

    score += Math.floor(Math.abs((dark * 100) / (size * size) - 50) / 5) * 10;

    return score;
}

function encodeWith(text, ecc) {
    const bytes = utf8(text);
    let version = 1;

    while (version <= 10) {
        const capacity = dataCapacity(version, ecc);
        const lengthBits = version >= 10 ? 16 : 8;

        if (4 + lengthBits + bytes.length * 8 <= capacity * 8) {
            break;
        }

        version += 1;
    }

    if (version > 10) {
        return null;
    }

    const codewords = buildCodewords(bytes, version, ecc);
    const data = interleave(codewords, version, ecc);
    const bits = [];

    for (const byte of data) {
        for (let i = 7; i >= 0; i -= 1) {
            bits.push((byte >> i) & 1);
        }
    }

    const base = buildBase(version);
    const positions = placeData(base, bits);
    let best = null;

    for (let mask = 0; mask < 8; mask += 1) {
        const modules = base.modules.map((row) => row.slice());

        for (const [row, col] of positions) {
            if (maskBit(mask, row, col)) {
                modules[row][col] = !modules[row][col];
            }
        }

        placeVersion(modules, base.size, version);
        placeFormat(modules, base.size, ecc, mask);

        const score = penalty(modules, base.size);

        if (best === null || score < best.score) {
            best = { score, modules };
        }
    }

    return { size: base.size, modules: best.modules, version, ecc };
}

/** Encode text as a QR matrix; ECC M first, L when the URL is long. */
export function encode(text) {
    return encodeWith(String(text), 'M') || encodeWith(String(text), 'L');
}

/** Draw the QR into a container as an SVG. Returns false when it does not fit. */
export function render(container, text) {
    const qr = encode(text);

    if (!qr || !container) {
        return false;
    }

    const svg = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
    const path = [];

    svg.setAttribute('viewBox', `0 0 ${qr.size} ${qr.size}`);
    svg.setAttribute('shape-rendering', 'crispEdges');
    svg.setAttribute('aria-hidden', 'true');
    svg.setAttribute('focusable', 'false');

    for (let row = 0; row < qr.size; row += 1) {
        for (let col = 0; col < qr.size; col += 1) {
            if (qr.modules[row][col]) {
                path.push(`M${col} ${row}h1v1h-1z`);
            }
        }
    }

    const shape = document.createElementNS('http://www.w3.org/2000/svg', 'path');

    shape.setAttribute('d', path.join(''));
    shape.setAttribute('fill', 'currentColor');
    svg.append(shape);
    container.replaceChildren(svg);

    return true;
}
