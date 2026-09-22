/**
 * The one way the programme writes a time (design §5, D-AGD-04).
 *
 * `startsAt` and `endsAt` on a `ProgrammeCellData` are **wall clocks in the
 * event's own timezone**, `HH:MM`, already resolved by `ProgrammeBuilder` —
 * not instants. They are printed exactly as the organizer promised them
 * (ADR-0027), which is why they do not go through `@/lib/datetime`: that
 * renders in the *reader's* zone, and a participant reading the programme
 * from another country must still see the hall's clock, not their own.
 *
 * Each file that prints one carries a `datetime-raw:` marker saying so, for
 * the sweep `tests/Unit/DateTimeRenderingTest.php` starts from.
 *
 * Three shapes, because D-AGD-04 allows three: both ends, a start only
 * ("Einlass ab 12:00"), an end only ("bis 08:00"). The cell takes the whole
 * object rather than the two strings so the call site reads as one value
 * and not as two timestamps handed around loose.
 */
export type TimeSpan = {
    startsAt: string | null;
    endsAt: string | null;
};

export function timeLabel(span: TimeSpan): string {
    if (span.startsAt !== null && span.endsAt !== null) {
        return `${span.startsAt}–${span.endsAt}`;
    }

    if (span.startsAt !== null) {
        return `ab ${span.startsAt}`;
    }

    if (span.endsAt !== null) {
        return `bis ${span.endsAt}`;
    }

    return '';
}

/**
 * Where an entry sorts in a chronological list: its start, or its end when
 * it only has one. Zero-padded `HH:MM` sorts correctly as a string, which
 * is the same fact `ProgrammeBuilder::rowTimes()` relies on.
 */
export function sortKey(span: TimeSpan): string {
    return span.startsAt ?? span.endsAt ?? '';
}
