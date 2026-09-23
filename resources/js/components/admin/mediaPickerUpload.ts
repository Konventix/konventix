/**
 * The picker's own upload rules (#292), kept out of the component so they
 * are testable without a DOM. They mirror what `MediaAdminController`
 * enforces — the client says no first so a 12 MiB drop fails with a
 * sentence rather than after the bytes have travelled — but the server's
 * `mimes` rule stays the judge: it reads the file's real type, this only
 * reads what the browser claims.
 */
import { trans } from 'laravel-vue-i18n';

export const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;

/** No SVG (#252) and no PDF: a picker chooses a picture. */
export const ACCEPTED_IMAGE_TYPES = [
    'image/jpeg',
    'image/png',
    'image/gif',
    'image/webp',
] as const;

export const ACCEPT_ATTRIBUTE = ACCEPTED_IMAGE_TYPES.join(',');

type Candidate = { size: number; type: string; name: string };

/** The sentence to show under the input, or null when the file may go. */
export function refuseUploadCandidate(file: Candidate): string | null {
    if (!(ACCEPTED_IMAGE_TYPES as readonly string[]).includes(file.type)) {
        return trans(':file is not a JPEG, PNG, GIF or WebP image.', {
            file: file.name,
        });
    }

    if (file.size > MAX_UPLOAD_BYTES) {
        return trans(':file is larger than 10 MB.', { file: file.name });
    }

    return null;
}

/**
 * A fresh upload goes to the front of the candidate list, newest first as
 * the library orders them, replacing any earlier copy of the same id.
 */
export function prependCandidate<T extends { id: string }>(
    candidates: readonly T[],
    item: T,
): T[] {
    return [
        item,
        ...candidates.filter((candidate) => candidate.id !== item.id),
    ];
}
