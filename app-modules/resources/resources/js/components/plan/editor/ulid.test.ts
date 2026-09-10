import { describe, expect, it } from 'vitest';
import { newUlid } from './ulid';

/**
 * Element ids are minted in the browser and never change (design §5), and
 * the server validates them with Laravel's `ulid` rule: 26 Crockford
 * base32 characters, time-ordered, first character 0–7.
 */
describe('newUlid', () => {
    const zeros = (bytes: Uint8Array): Uint8Array => bytes.fill(0);

    it('is 26 Crockford characters', () => {
        expect(newUlid()).toMatch(/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/);
    });

    it('encodes the millisecond timestamp in the first ten characters', () => {
        expect(newUlid(0, zeros)).toBe('00000000000000000000000000');
        expect(newUlid(1, zeros)).toBe('00000000010000000000000000');
        expect(newUlid(32, zeros)).toBe('00000000100000000000000000');
    });

    it('sorts later timestamps after earlier ones', () => {
        expect(newUlid(2000, zeros) > newUlid(1999, zeros)).toBe(true);
    });

    it('differs between calls with real randomness', () => {
        expect(newUlid()).not.toBe(newUlid());
    });
});
