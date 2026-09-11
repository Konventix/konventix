<?php

declare(strict_types=1);

namespace Konventix\Platform\Calendar;

use DateTimeInterface;

/**
 * A hand-rolled RFC 5545 encoder (spec §6.1), not a package: one VCALENDAR
 * holding one VEVENT is the entire surface this needs, and pulling in a
 * calendar library for three constant lines and a fold algorithm would be
 * a dependency for nothing.
 *
 * Every module reaches this the way they already reach `MediaLibrary`
 * (2026-09-11 planning amendment, spec §13): it takes scalars and returns
 * bytes, so Registration can attach a calendar file to the confirmation
 * mail without importing Event (ADR-0004) — Event, Registration and
 * Admission each keep their own read model of a venue's name and an
 * event's time, and hand the resolved strings and instants in here.
 *
 * `$startsAt`/`$endsAt`/`$lastModifiedAt` are emitted as UTC instants
 * (`…Z`), never with a `TZID`, so there is no `VTIMEZONE` block. A correct
 * `VTIMEZONE` needs full daylight-saving transition rules for the zone; a
 * UTC instant needs none of that and every client renders it correctly in
 * its own zone regardless of where it runs. The event itself still stores
 * wall clock plus an IANA zone (ADR-0027) — converting to UTC is this
 * method's job, not the caller's.
 *
 * No `SEQUENCE`: `LAST-MODIFIED` is the property clients use for that, and
 * the repository has no revision counter to derive a sequence number from.
 */
final class CalendarFile
{
    private function __construct() {}

    /**
     * `$uid` is composed by the caller (the event id plus the brand's
     * host) and passed in whole — stability of the identifier for
     * re-importing calendar clients is the caller's contract, not this
     * encoder's concern.
     *
     * `$description`, `$location`, `$url` and `$lastModifiedAt` are
     * omitted entirely when null, never emitted as an empty property — an
     * event with no venue (spec §11) still produces a valid file this way.
     */
    public static function forEvent(
        string $uid,
        string $summary,
        ?string $description,
        ?string $location,
        ?string $url,
        DateTimeInterface $startsAt,
        DateTimeInterface $endsAt,
        ?DateTimeInterface $lastModifiedAt = null,
    ): string {
        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Konventix//Calendar 1.0//EN',
            'CALSCALE:GREGORIAN',
            'METHOD:PUBLISH',
            'BEGIN:VEVENT',
            self::property('UID', self::escapeText($uid)),
            self::property('DTSTAMP', self::utcStamp(now())),
            self::property('DTSTART', self::utcStamp($startsAt)),
            self::property('DTEND', self::utcStamp($endsAt)),
            self::property('SUMMARY', self::escapeText($summary)),
        ];

        if ($description !== null) {
            $lines[] = self::property('DESCRIPTION', self::escapeText($description));
        }

        if ($location !== null) {
            $lines[] = self::property('LOCATION', self::escapeText($location));
        }

        if ($url !== null) {
            $lines[] = self::property('URL', $url);
        }

        if ($lastModifiedAt !== null) {
            $lines[] = self::property('LAST-MODIFIED', self::utcStamp($lastModifiedAt));
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        return implode("\r\n", array_map(self::foldLine(...), $lines))."\r\n";
    }

    private static function property(string $name, string $value): string
    {
        return $name.':'.$value;
    }

    /**
     * The UTC instant for any wall clock, converted via the Unix
     * timestamp rather than a timezone swap on `$moment` — a
     * `DateTimeInterface` carries no guarantee of being mutable, and
     * reading the absolute instant sidesteps that regardless of which zone
     * the caller's clock is attached to. This is the UTC conversion spec
     * §6.1 asks for: `$moment` may be a Carbon instance on the event's own
     * IANA zone (ADR-0027), and the wire format is always `…Z`, never a
     * `TZID`.
     */
    private static function utcStamp(DateTimeInterface $moment): string
    {
        return gmdate('Ymd\THis\Z', $moment->getTimestamp());
    }

    /**
     * `\`, `;`, `,` and newlines escaped in that order (RFC 5545 §3.3.11)
     * — backslash first, because every escape this method adds afterwards
     * is itself a backslash sequence, and escaping backslash last would
     * double-escape them.
     */
    private static function escapeText(string $value): string
    {
        $value = str_replace('\\', '\\\\', $value);
        $value = str_replace(';', '\\;', $value);
        $value = str_replace(',', '\\,', $value);

        return str_replace(["\r\n", "\r", "\n"], '\\n', $value);
    }

    /**
     * RFC 5545 §3.1 line folding: no physical line exceeds 75 octets
     * (a continuation line's single leading space counts inside that
     * budget), and the split point counts octets, never characters, so a
     * multi-byte UTF-8 character is never divided between two lines — a
     * continuation byte (`10xxxxxx`) sitting right at the cut point pushes
     * the boundary back until it lands on a character start.
     */
    private static function foldLine(string $line): string
    {
        $octets = strlen($line);

        if ($octets <= 75) {
            return $line;
        }

        $folded = '';
        $offset = 0;
        $limit = 75;

        while ($offset < $octets) {
            $end = min($offset + $limit, $octets);

            while ($end < $octets && (ord($line[$end]) & 0xC0) === 0x80) {
                $end--;
            }

            $folded .= ($offset === 0 ? '' : "\r\n ").substr($line, $offset, $end - $offset);
            $offset = $end;
            $limit = 74;
        }

        return $folded;
    }
}
