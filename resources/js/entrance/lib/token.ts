import { ed25519 } from '@noble/curves/ed25519.js';

/**
 * Browser half of the KVX1 admission token (ADR-0033).
 *
 * This is a deliberate reimplementation of
 * `Konventix\Admission\Tokens\AdmissionTokenCodec`, not a port of convenience:
 * that class is database-free precisely so this logic can exist unchanged at a
 * door with no uplink, but it calls PHP's libsodium extension, which cannot
 * run in a browser. The two must stay byte-compatible — the signing input,
 * the base64url framing and the body field names are all contract, and
 * `AdmissionTokenTest` on the PHP side plus `token.test.ts` here both pin
 * them.
 *
 * Verification answers authenticity and expiry only. Revocation and
 * already-checked-in are negative facts a signature cannot carry, which is
 * exactly why ADR-0033 pairs this with the delta feed.
 */

export const TOKEN_PREFIX = 'KVX1';

export type AdmissionTokenPayload = {
    /** Admission ULID. */
    tid: string;
    /** Event ULID. */
    evt: string;
    nonce: string;
    /** Issued at, unix seconds. */
    iat: number;
    /** Expires at, unix seconds. */
    exp: number;
    /** Event-day ULIDs; empty means every day of the event. */
    days: string[];
};

export type SigningKey = {
    kid: string;
    alg: string;
    public_key: string;
    expires_at: string;
};

function base64UrlDecode(value: string): Uint8Array | null {
    try {
        const padded = value.replace(/-/g, '+').replace(/_/g, '/');
        const binary = atob(padded + '='.repeat((4 - (padded.length % 4)) % 4));
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes;
    } catch {
        return null;
    }
}

function base64Decode(value: string): Uint8Array | null {
    try {
        const binary = atob(value);
        const bytes = new Uint8Array(binary.length);

        for (let i = 0; i < binary.length; i++) {
            bytes[i] = binary.charCodeAt(i);
        }

        return bytes;
    } catch {
        return null;
    }
}

/**
 * The `kid` a token claims, without trusting anything else about it — needed
 * to pick a public key before any verification has happened.
 */
export function kidOf(token: string): string | null {
    const parts = token.split('.');

    if (parts.length !== 4 || parts[0] !== TOKEN_PREFIX || parts[1] === '') {
        return null;
    }

    return parts[1];
}

/**
 * Verifies the signature and returns the payload, or null when the token is
 * malformed, unknown or forged.
 *
 * The signing input covers the `kid` as well as the body, so a token cannot be
 * re-pointed at another key by rewriting that segment — the PHP side asserts
 * the same thing.
 */
export function verifyToken(
    token: string,
    keys: SigningKey[],
): AdmissionTokenPayload | null {
    const parts = token.split('.');

    if (parts.length !== 4 || parts[0] !== TOKEN_PREFIX) {
        return null;
    }

    const [, kid, body, signature] = parts;

    const key = keys.find((candidate) => candidate.kid === kid);

    if (key === undefined) {
        return null;
    }

    const rawSignature = base64UrlDecode(signature);
    const publicKey = base64Decode(key.public_key);

    if (
        rawSignature === null ||
        publicKey === null ||
        rawSignature.length !== 64 ||
        publicKey.length !== 32
    ) {
        return null;
    }

    const signingInput = new TextEncoder().encode(
        `${TOKEN_PREFIX}.${kid}.${body}`,
    );

    let valid: boolean;

    try {
        valid = ed25519.verify(rawSignature, signingInput, publicKey);
    } catch {
        return null;
    }

    if (!valid) {
        return null;
    }

    const decoded = base64UrlDecode(body);

    if (decoded === null) {
        return null;
    }

    try {
        const parsed = JSON.parse(
            new TextDecoder().decode(decoded),
        ) as Partial<AdmissionTokenPayload>;

        return {
            tid: String(parsed.tid ?? ''),
            evt: String(parsed.evt ?? ''),
            nonce: String(parsed.nonce ?? ''),
            iat: Number(parsed.iat ?? 0),
            exp: Number(parsed.exp ?? 0),
            days: Array.isArray(parsed.days) ? parsed.days.map(String) : [],
        };
    } catch {
        return null;
    }
}

/** Whether the token admits on a given event day. Empty `days` = whole event. */
export function admitsOnDay(
    payload: AdmissionTokenPayload,
    dayId: string | null,
): boolean {
    if (payload.days.length === 0) {
        return true;
    }

    return dayId !== null && payload.days.includes(dayId);
}

export function isExpired(
    payload: AdmissionTokenPayload,
    nowSeconds: number,
): boolean {
    return payload.exp <= nowSeconds;
}
