import { ed25519 } from '@noble/curves/ed25519.js';
import { describe, expect, it } from 'vitest';
import { REJECTION, checkedInKey, dayCovering, evaluateScan } from './evaluate';
import type { LocalState } from './evaluate';
import { kidOf, verifyToken } from './token';
import type { AdmissionTokenPayload, SigningKey } from './token';

/**
 * The door's offline decision (ADR-0033).
 *
 * These tests exist to pin the client to the server: the reason-code strings
 * and the order in which checks fire are copied from
 * `Konventix\Admission\Enums\CheckInRejectionReason` and
 * `Konventix\Admission\Actions\RecordScan`. If they drift, a scan decided at
 * the door and the same scan reconciled later would disagree — someone turned
 * away would be recorded as admitted.
 */

function base64(bytes: Uint8Array): string {
    return btoa(String.fromCharCode(...bytes));
}

function base64Url(input: string): string {
    return btoa(input)
        .replace(/\+/g, '-')
        .replace(/\//g, '_')
        .replace(/=+$/, '');
}

function mintToken(
    payload: Partial<AdmissionTokenPayload>,
    kid = 'testkid',
): { token: string; key: SigningKey } {
    const secret = ed25519.utils.randomSecretKey();
    const publicKey = ed25519.getPublicKey(secret);

    const body = base64Url(
        JSON.stringify({
            tid: '01ADMISSION',
            evt: '01EVENT',
            nonce: 'n1',
            iat: 1_700_000_000,
            exp: 4_100_000_000,
            days: [],
            ...payload,
        }),
    );

    const signature = ed25519.sign(
        new TextEncoder().encode(`KVX1.${kid}.${body}`),
        secret,
    );

    return {
        token: `KVX1.${kid}.${body}.${base64(signature).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')}`,
        key: {
            kid,
            alg: 'Ed25519',
            public_key: base64(publicKey),
            expires_at: '2030-01-01T00:00:00+00:00',
        },
    };
}

function state(overrides: Partial<LocalState> = {}): LocalState {
    return {
        eventId: '01EVENT',
        keys: [],
        days: [],
        reentryPolicy: 'unlimited',
        revoked: new Set(),
        checkedIn: new Set(),
        ...overrides,
    };
}

const NOW = new Date('2026-08-12T12:00:00');

describe('token verification', () => {
    it('round-trips a token minted with the same framing the server uses', () => {
        const { token, key } = mintToken({});

        expect(kidOf(token)).toBe('testkid');
        expect(verifyToken(token, [key])?.tid).toBe('01ADMISSION');
    });

    it('rejects a token whose body was swapped', () => {
        const { token, key } = mintToken({});
        const parts = token.split('.');
        const forged = [
            parts[0],
            parts[1],
            base64Url(JSON.stringify({ tid: 'OTHER' })),
            parts[3],
        ].join('.');

        expect(verifyToken(forged, [key])).toBeNull();
    });

    it('rejects a token re-pointed at another kid, because the kid is signed', () => {
        const { token, key } = mintToken({});
        const parts = token.split('.');

        expect(
            verifyToken([parts[0], 'elsewhere', parts[2], parts[3]].join('.'), [
                key,
            ]),
        ).toBeNull();
    });

    it('rejects a kid it holds no key for', () => {
        const { token } = mintToken({});

        expect(verifyToken(token, [])).toBeNull();
    });
});

describe('offline decision', () => {
    it('admits a valid token', () => {
        const { token, key } = mintToken({});

        expect(evaluateScan(token, state({ keys: [key] }), NOW)).toEqual({
            accepted: true,
            admissionId: '01ADMISSION',
            dayId: null,
        });
    });

    it('rejects a forged token as invalid_signature', () => {
        const { key } = mintToken({});

        expect(
            evaluateScan(
                'KVX1.testkid.bogus.bogus',
                state({ keys: [key] }),
                NOW,
            ),
        ).toMatchObject({
            accepted: false,
            reason: REJECTION.InvalidSignature,
        });
    });

    it('rejects an expired token', () => {
        const { token, key } = mintToken({ exp: 1_700_000_100 });

        expect(evaluateScan(token, state({ keys: [key] }), NOW)).toMatchObject({
            reason: REJECTION.TokenExpired,
        });
    });

    it('rejects a token minted for another event', () => {
        const { token, key } = mintToken({ evt: '01ELSEWHERE' });

        expect(evaluateScan(token, state({ keys: [key] }), NOW)).toMatchObject({
            reason: REJECTION.WrongEvent,
        });
    });

    it('rejects a scan outside every event day', () => {
        const { token, key } = mintToken({});
        const days = [
            { day_id: 'd1', date: '2026-08-20', sequence: 1, label: null },
        ];

        expect(
            evaluateScan(token, state({ keys: [key], days }), NOW),
        ).toMatchObject({
            reason: REJECTION.OutsideEventDay,
        });
    });

    it('rejects a day-scoped token on a day it does not cover', () => {
        const days = [
            { day_id: 'd1', date: '2026-08-12', sequence: 1, label: null },
            { day_id: 'd2', date: '2026-08-13', sequence: 2, label: null },
        ];
        const { token, key } = mintToken({ days: ['d2'] });

        expect(
            evaluateScan(token, state({ keys: [key], days }), NOW),
        ).toMatchObject({
            reason: REJECTION.NotValidOnThisDay,
        });
    });

    it('rejects an admission the delta says is revoked', () => {
        const { token, key } = mintToken({});
        const revoked = new Set(['01ADMISSION']);

        expect(
            evaluateScan(token, state({ keys: [key], revoked }), NOW),
        ).toMatchObject({
            reason: REJECTION.AdmissionNotValid,
        });
    });

    it('enforces once_only from the checked-in set', () => {
        const { token, key } = mintToken({});
        const checkedIn = new Set(['01ADMISSION']);

        expect(
            evaluateScan(
                token,
                state({ keys: [key], reentryPolicy: 'once_only', checkedIn }),
                NOW,
            ),
        ).toMatchObject({
            reason: REJECTION.ReentryNotPermitted,
        });
    });

    it('enforces once_per_day per day, not outright', () => {
        const days = [
            { day_id: 'd1', date: '2026-08-12', sequence: 1, label: null },
            { day_id: 'd2', date: '2026-08-13', sequence: 2, label: null },
        ];
        const { token, key } = mintToken({});
        const checkedIn = new Set(['01ADMISSION:d1']);

        expect(
            evaluateScan(
                token,
                state({
                    keys: [key],
                    days,
                    reentryPolicy: 'once_per_day',
                    checkedIn,
                }),
                NOW,
            ),
        ).toMatchObject({ reason: REJECTION.ReentryNotPermitted });

        // Next day, same admission: a fresh allowance.
        expect(
            evaluateScan(
                token,
                state({
                    keys: [key],
                    days,
                    reentryPolicy: 'once_per_day',
                    checkedIn,
                }),
                new Date('2026-08-13T09:00:00'),
            ),
        ).toMatchObject({ accepted: true, dayId: 'd2' });
    });

    it('admits repeatedly under unlimited even when already checked in', () => {
        const { token, key } = mintToken({});
        const checkedIn = new Set(['01ADMISSION']);

        expect(
            evaluateScan(token, state({ keys: [key], checkedIn }), NOW),
        ).toMatchObject({ accepted: true });
    });

    it('checks signature before anything the delta could say', () => {
        // Order matters: a forged token must never be reported as "revoked",
        // which would tell the holder their id exists.
        const revoked = new Set(['01ADMISSION']);
        const { key } = mintToken({});

        expect(
            evaluateScan(
                'KVX1.testkid.x.y',
                state({ keys: [key], revoked }),
                NOW,
            ),
        ).toMatchObject({
            reason: REJECTION.InvalidSignature,
            admissionId: null,
        });
    });
});

describe('helpers', () => {
    it('resolves the day covering a moment in local wall-clock terms', () => {
        const days = [
            { day_id: 'd1', date: '2026-08-12', sequence: 1, label: null },
            { day_id: 'd2', date: '2026-08-13', sequence: 2, label: null },
        ];

        expect(dayCovering(days, NOW)?.day_id).toBe('d1');
        expect(dayCovering(days, new Date('2026-08-14T10:00:00'))).toBeNull();
    });

    it('keys checked-in state per day only under once_per_day', () => {
        expect(checkedInKey('a', 'once_per_day', 'd1')).toBe('a:d1');
        expect(checkedInKey('a', 'once_only', 'd1')).toBe('a');
        expect(checkedInKey('a', 'unlimited', null)).toBe('a');
    });
});
