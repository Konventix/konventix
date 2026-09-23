/**
 * The carousel's arithmetic, kept out of the component so it can be tested
 * without a DOM (vitest runs in the `node` environment with no
 * `@vue/test-utils` — .ai/rules/js.md). `VenueCarousel.vue` owns the
 * markup, the focus and the live region; this owns which slide is next.
 */
import { trans } from 'laravel-vue-i18n';

/** Clamp an index into a carousel of `count` slides. Empty means 0. */
export function clampSlide(index: number, count: number): number {
    if (count <= 0) {
        return 0;
    }

    return Math.min(Math.max(Math.trunc(index), 0), count - 1);
}

/** The slide after `current`, wrapping back to the first one at the end. */
export function nextSlide(current: number, count: number): number {
    if (count <= 0) {
        return 0;
    }

    return (clampSlide(current, count) + 1) % count;
}

/** The slide before `current`, wrapping to the last one at the start. */
export function previousSlide(current: number, count: number): number {
    if (count <= 0) {
        return 0;
    }

    return (clampSlide(current, count) + count - 1) % count;
}

/**
 * Which slide a key press moves to, or `null` when the key is none of the
 * carousel's business — the component only calls `preventDefault()` for a
 * key that answered a number, so `Tab` keeps tabbing.
 *
 * Arrow keys step and wrap (the pattern the prev/next buttons follow),
 * Home and End jump to the ends.
 */
export function slideForKey(
    key: string,
    current: number,
    count: number,
): number | null {
    if (count <= 0) {
        return null;
    }

    switch (key) {
        case 'ArrowRight':
            return nextSlide(current, count);
        case 'ArrowLeft':
            return previousSlide(current, count);
        case 'Home':
            return 0;
        case 'End':
            return count - 1;
        default:
            return null;
    }
}

/**
 * What the live region announces after a move. Counted from one, because
 * it is read out to a person, not to an array.
 */
export function slideStatus(current: number, count: number): string {
    if (count <= 0) {
        return trans('No pictures');
    }

    return trans('Picture :number of :count', {
        number: String(clampSlide(current, count) + 1),
        count: String(count),
    });
}
