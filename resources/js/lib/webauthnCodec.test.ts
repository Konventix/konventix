import { describe, expect, it } from 'vitest';
import {
    base64UrlDecode,
    base64UrlEncode,
    ceremonyFailureMessage,
    classifyCeremonyError,
    serializeAssertion,
    serializeAttestation,
    toCreationOptions,
    toRequestOptions,
} from './webauthnCodec';

/**
 * Bugfix round 2026-09-21, Task 16: the hand-written passkey client. These
 * pin the byte-level translation between the package's JSON and the WebAuthn
 * API; the fetch and `navigator.credentials` wiring is in `webauthn.ts` and
 * is exercised in a browser, not here.
 */
const bytes = (...values: number[]): Uint8Array => new Uint8Array(values);
const buffer = (...values: number[]): ArrayBuffer =>
    bytes(...values).buffer as ArrayBuffer;

describe('base64url', () => {
    it('encodes without padding and with the url-safe alphabet', () => {
        // 0xfb 0xff 0xbf is "+/+/" in standard base64.
        expect(base64UrlEncode(bytes(0xfb, 0xff, 0xbf))).toBe('-_-_');
        expect(base64UrlEncode(bytes(0x66))).toBe('Zg');
        expect(base64UrlEncode(bytes(0x66, 0x6f))).toBe('Zm8');
        expect(base64UrlEncode(bytes(0x66, 0x6f, 0x6f))).toBe('Zm9v');
        expect(base64UrlEncode(new Uint8Array(0))).toBe('');
    });

    it('accepts an ArrayBuffer as well as a view', () => {
        expect(base64UrlEncode(buffer(0x66, 0x6f, 0x6f))).toBe('Zm9v');
    });

    it('round-trips every byte value', () => {
        const all = new Uint8Array(256).map((_, index) => index);

        expect(base64UrlDecode(base64UrlEncode(all))).toEqual(all);
    });

    it('decodes padded and standard-alphabet input too', () => {
        expect(base64UrlDecode('Zm8=')).toEqual(bytes(0x66, 0x6f));
        expect(base64UrlDecode('+/+/')).toEqual(bytes(0xfb, 0xff, 0xbf));
        expect(base64UrlDecode('')).toEqual(new Uint8Array(0));
    });
});

describe('toCreationOptions', () => {
    it('turns the package registration options into API options', () => {
        const options = toCreationOptions({
            challenge: 'AQID',
            rp: { id: 'sxlan.test', name: 'sxlan.test' },
            user: { id: 'BAUG', name: 'a@example.test', displayName: 'A' },
            pubKeyCredParams: [{ type: 'public-key', alg: -7 }],
            timeout: 60000,
            authenticatorSelection: {
                residentKey: 'required',
                userVerification: 'required',
            },
            attestation: 'none',
            excludeCredentials: [
                { type: 'public-key', id: 'BwgJ', transports: ['internal'] },
            ],
        });

        expect(new Uint8Array(options.challenge as ArrayBuffer)).toEqual(
            bytes(1, 2, 3),
        );
        expect(new Uint8Array(options.user.id as ArrayBuffer)).toEqual(
            bytes(4, 5, 6),
        );
        expect(options.rp.id).toBe('sxlan.test');
        expect(options.user.name).toBe('a@example.test');
        expect(options.excludeCredentials).toHaveLength(1);
        expect(
            new Uint8Array(options.excludeCredentials?.[0]?.id as ArrayBuffer),
        ).toEqual(bytes(7, 8, 9));
        expect(options.excludeCredentials?.[0]?.transports).toEqual([
            'internal',
        ]);
        expect(options.authenticatorSelection?.residentKey).toBe('required');
        expect(options.attestation).toBe('none');
    });

    it('leaves an absent exclude list absent', () => {
        const options = toCreationOptions({
            challenge: 'AQID',
            rp: { name: 'localhost' },
            user: { id: 'BAUG', name: 'a', displayName: 'a' },
            pubKeyCredParams: [],
        });

        expect(options.excludeCredentials).toBeUndefined();
    });
});

describe('toRequestOptions', () => {
    it('turns the package login options into API options', () => {
        const options = toRequestOptions({
            challenge: 'AQID',
            rpId: 'localhost',
            timeout: 60000,
            allowCredentials: [],
            userVerification: 'required',
        });

        expect(new Uint8Array(options.challenge as ArrayBuffer)).toEqual(
            bytes(1, 2, 3),
        );
        expect(options.rpId).toBe('localhost');
        expect(options.allowCredentials).toEqual([]);
        expect(options.userVerification).toBe('required');
    });
});

describe('serializeAttestation', () => {
    it('encodes every buffer and carries the transports', () => {
        const json = serializeAttestation({
            id: 'AQID',
            rawId: buffer(1, 2, 3),
            type: 'public-key',
            authenticatorAttachment: 'platform',
            response: {
                clientDataJSON: buffer(4),
                attestationObject: buffer(5, 6),
                getTransports: () => ['internal', 'hybrid'],
            },
            getClientExtensionResults: () => ({ credProps: { rk: true } }),
        });

        expect(json).toEqual({
            id: 'AQID',
            rawId: 'AQID',
            type: 'public-key',
            authenticatorAttachment: 'platform',
            response: {
                clientDataJSON: 'BA',
                attestationObject: 'BQY',
                transports: ['internal', 'hybrid'],
            },
            clientExtensionResults: { credProps: { rk: true } },
        });
    });

    it('tolerates an authenticator without getTransports', () => {
        const json = serializeAttestation({
            id: 'AQID',
            rawId: buffer(1, 2, 3),
            type: 'public-key',
            response: {
                clientDataJSON: buffer(4),
                attestationObject: buffer(5, 6),
            },
        });

        expect(json.response.transports).toEqual([]);
        expect(json.authenticatorAttachment).toBeUndefined();
        expect(json.clientExtensionResults).toEqual({});
    });
});

describe('serializeAssertion', () => {
    it('encodes every buffer and drops a missing user handle', () => {
        const json = serializeAssertion({
            id: 'AQID',
            rawId: buffer(1, 2, 3),
            type: 'public-key',
            response: {
                clientDataJSON: buffer(4),
                authenticatorData: buffer(5, 6),
                signature: buffer(7, 8, 9),
                userHandle: null,
            },
        });

        expect(json.response).toEqual({
            clientDataJSON: 'BA',
            authenticatorData: 'BQY',
            signature: 'BwgJ',
            userHandle: undefined,
        });
        expect(json.rawId).toBe('AQID');
    });

    it('carries the user handle a discoverable credential returns', () => {
        const json = serializeAssertion({
            id: 'AQID',
            rawId: buffer(1, 2, 3),
            type: 'public-key',
            response: {
                clientDataJSON: buffer(4),
                authenticatorData: buffer(5, 6),
                signature: buffer(7, 8, 9),
                userHandle: buffer(10),
            },
        });

        expect(json.response.userHandle).toBe('Cg');
    });
});

describe('classifyCeremonyError', () => {
    it('reads the DOMException name', () => {
        expect(classifyCeremonyError({ name: 'NotAllowedError' })).toBe(
            'cancelled',
        );
        expect(classifyCeremonyError({ name: 'AbortError' })).toBe('cancelled');
        expect(classifyCeremonyError({ name: 'InvalidStateError' })).toBe(
            'already-registered',
        );
        expect(classifyCeremonyError({ name: 'SecurityError' })).toBe('failed');
        expect(classifyCeremonyError(new Error('boom'))).toBe('failed');
        expect(classifyCeremonyError(undefined)).toBe('failed');
    });

    it('has a sentence for every failure', () => {
        for (const failure of [
            'unsupported',
            'password-confirmation-required',
            'cancelled',
            'already-registered',
            'failed',
        ] as const) {
            expect(ceremonyFailureMessage(failure)).not.toBe('');
        }
    });
});
