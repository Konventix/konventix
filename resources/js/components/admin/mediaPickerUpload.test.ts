import { describe, expect, it } from 'vitest';
import {
    ACCEPT_ATTRIBUTE,
    MAX_UPLOAD_BYTES,
    prependCandidate,
    refuseUploadCandidate,
} from './mediaPickerUpload';

describe('refuseUploadCandidate', () => {
    it('lets a small raster image through', () => {
        expect(
            refuseUploadCandidate({
                size: 1024,
                type: 'image/png',
                name: 'a.png',
            }),
        ).toBeNull();
    });

    it('refuses an SVG before it is sent (#252)', () => {
        expect(
            refuseUploadCandidate({
                size: 10,
                type: 'image/svg+xml',
                name: 'a.svg',
            }),
        ).toMatch(/not a JPEG/);
    });

    it('refuses a PDF: the picker chooses pictures', () => {
        expect(
            refuseUploadCandidate({
                size: 10,
                type: 'application/pdf',
                name: 'a.pdf',
            }),
        ).toMatch(/not a JPEG/);
    });

    it('refuses a file over 10 MB with a sentence naming it', () => {
        expect(
            refuseUploadCandidate({
                size: MAX_UPLOAD_BYTES + 1,
                type: 'image/jpeg',
                name: 'huge.jpg',
            }),
        ).toBe('huge.jpg is larger than 10 MB.');
    });

    it('offers only raster types to the file dialog', () => {
        expect(ACCEPT_ATTRIBUTE).toBe(
            'image/jpeg,image/png,image/gif,image/webp',
        );
    });
});

describe('prependCandidate', () => {
    it('puts the new item first', () => {
        expect(
            prependCandidate([{ id: 'a' }, { id: 'b' }], { id: 'c' }),
        ).toEqual([{ id: 'c' }, { id: 'a' }, { id: 'b' }]);
    });

    it('replaces an earlier copy of the same id instead of duplicating it', () => {
        expect(
            prependCandidate(
                [
                    { id: 'a', n: 1 },
                    { id: 'b', n: 1 },
                ],
                { id: 'b', n: 2 },
            ),
        ).toEqual([
            { id: 'b', n: 2 },
            { id: 'a', n: 1 },
        ]);
    });

    it('does not mutate the list it was given', () => {
        const list = [{ id: 'a' }];
        prependCandidate(list, { id: 'b' });
        expect(list).toEqual([{ id: 'a' }]);
    });
});
