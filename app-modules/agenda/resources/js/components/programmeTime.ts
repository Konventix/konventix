import { trans } from 'laravel-vue-i18n';
import { formatTime } from '@/lib/datetime';

/**
 * The one way the programme writes a time (design §5, D-AGD-04).
 *
 * `startsAt` and `endsAt` on a `ProgrammeCellData` are **wall clocks in the
 * event's own timezone**, `HH:MM`, already resolved by `ProgrammeBuilder` —
 * not instants. They still go through `formatTime()` like every other time
 * on a screen (`.ai/rules/js.md`), and that is safe *because* they are wall
 * clocks: the helper matches a bare `HH:MM` first and writes those digits
 * out as they came, so the only thing it changes is the operator's 12- or
 * 24-hour choice. Nothing is converted into the reader's zone, which is
 * what must not happen here — a participant reading the programme from
 * another country has to see the hall's clock.
 *
 * Three shapes, because D-AGD-04 allows three: both ends, a start only
 * ("Einlass ab 12:00"), an end only ("bis 08:00"). The cell is taken whole
 * rather than as two strings so the call site reads as one value and not as
 * two timestamps handed around loose.
 */
export type TimeSpan = {
    startsAt: string | null;
    endsAt: string | null;
};

export function timeLabel(span: TimeSpan): string {
    if (span.startsAt !== null && span.endsAt !== null) {
        return `${formatTime(span.startsAt)}–${formatTime(span.endsAt)}`;
    }

    if (span.startsAt !== null) {
        return trans('from :time', { time: formatTime(span.startsAt) });
    }

    if (span.endsAt !== null) {
        return trans('until :time', { time: formatTime(span.endsAt) });
    }

    return '';
}

/**
 * Where an entry sorts in a chronological list: its start, or its end when
 * it only has one.
 *
 * Deliberately **not** formatted. Zero-padded 24-hour `HH:MM` sorts
 * correctly as a string — the same fact `ProgrammeBuilder::rowTimes()`
 * relies on — and a 12-hour rendering would put 10:00 PM before 9:00 PM.
 * Formatting is for the screen; this value never reaches one.
 */
export function sortKey(span: TimeSpan): string {
    return span.startsAt ?? span.endsAt ?? '';
}
