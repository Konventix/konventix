<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Konventix\Platform\Calendar\CalendarFile;

afterEach(function (): void {
    Carbon::setTestNow();
});

/**
 * Spec §6.1: one VCALENDAR holding one VEVENT — an event has no
 * sub-sessions, so there is never a second one — and no SEQUENCE: the
 * repository has no counter to derive one from, LAST-MODIFIED is what
 * clients use instead.
 */
it('emits a minimal event as CRLF-terminated ICS with no optional properties', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $ics = CalendarFile::forEvent(
        uid: 'event-01K5EXAMPLE@brand.konventix.test',
        summary: 'Konventix Summit',
        description: null,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    expect($ics)->toBe(implode("\r\n", [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Konventix//Calendar 1.0//EN',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'BEGIN:VEVENT',
        'UID:event-01K5EXAMPLE@brand.konventix.test',
        'DTSTAMP:20260911T090000Z',
        'DTSTART:20261001T080000Z',
        'DTEND:20261001T170000Z',
        'SUMMARY:Konventix Summit',
        'END:VEVENT',
        'END:VCALENDAR',
    ])."\r\n")
        ->not->toContain('DESCRIPTION:')
        ->not->toContain('LOCATION:')
        ->not->toContain('URL:')
        ->not->toContain('LAST-MODIFIED:')
        ->not->toContain('SEQUENCE:');
});

it('folds a long content line at 75 octets with a single leading space on the continuation', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    $summary = str_repeat('A', 100);

    $ics = CalendarFile::forEvent(
        uid: 'event-fold@brand.konventix.test',
        summary: $summary,
        description: null,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    // "SUMMARY:" is 8 octets; the first physical line carries exactly 75
    // octets (67 of the 100 A's), the continuation carries the rest behind
    // a single leading space.
    $expectedLine = 'SUMMARY:'.str_repeat('A', 67)."\r\n ".str_repeat('A', 33);

    expect($ics)->toContain($expectedLine);

    foreach (explode("\r\n", $ics) as $physicalLine) {
        expect(strlen($physicalLine))->toBeLessThanOrEqual(75);
    }
});

it('never splits a multi-byte character across a fold, even when the naive octet cut would land inside it', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    // "DESCRIPTION:" is 12 octets; 62 further ASCII octets land the naive
    // 75-octet cut on the first byte of the two-byte 'ü' (0xC3 0xBC) that
    // follows — exactly the case the octet-counting guard exists for.
    $description = str_repeat('A', 62).'ü'.str_repeat('B', 5);

    $ics = CalendarFile::forEvent(
        uid: 'event-multibyte@brand.konventix.test',
        summary: 'Multi-byte fold',
        description: $description,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    $expectedLine = 'DESCRIPTION:'.str_repeat('A', 62)."\r\n ".'ü'.str_repeat('B', 5);

    expect($ics)->toContain($expectedLine)
        ->and(mb_check_encoding($ics, 'UTF-8'))->toBeTrue();
});

it('escapes backslash, semicolon, comma and newlines inside a TEXT value, in that order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-09-11T09:00:00Z'));

    // Raw: one backslash, one semicolon, one comma, one real newline.
    $description = 'Tickets'.chr(92).'Admission;VIP,Row 1'."\n".'Gate A';

    $ics = CalendarFile::forEvent(
        uid: 'event-escape@brand.konventix.test',
        summary: 'Escaping',
        description: $description,
        location: null,
        url: null,
        startsAt: new DateTimeImmutable('2026-10-01T08:00:00Z'),
        endsAt: new DateTimeImmutable('2026-10-01T17:00:00Z'),
    );

    // Expected, in escaping order: \ -> \\, ; -> \;, , -> \,, newline -> \n
    // (a literal backslash followed by the letter n).
    $expected = 'Tickets'.chr(92).chr(92).'Admission'.chr(92).';VIP'.chr(92).',Row 1'.chr(92).'nGate A';

    expect(explode("\r\n", $ics))->toContain('DESCRIPTION:'.$expected);
});
