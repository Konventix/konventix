/**
 * A ULID minted in the browser (design §5: element ids are client-minted
 * and never change). Crockford base32, 48-bit millisecond time, 80 random
 * bits — the shape Laravel's `ulid` validation rule and `Str::isUlid`
 * accept. The random source is injectable so a test can pin the output.
 */
const ENCODING = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

function fillRandom(bytes: Uint8Array): Uint8Array {
    crypto.getRandomValues(bytes);

    return bytes;
}

export function newUlid(
    now: number = Date.now(),
    random: (bytes: Uint8Array) => Uint8Array = fillRandom,
): string {
    let time = now;
    let timePart = '';

    for (let index = 0; index < 10; index++) {
        timePart = ENCODING[time % 32] + timePart;
        time = Math.floor(time / 32);
    }

    const bytes = random(new Uint8Array(16));
    let randomPart = '';

    for (let index = 0; index < 16; index++) {
        randomPart += ENCODING[bytes[index] % 32];
    }

    return timePart + randomPart;
}
