<?php

declare(strict_types=1);

namespace Konventix\Community\Exceptions;

use Carbon\CarbonInterface;
use RuntimeException;

/**
 * Why a post was refused (spec §6, D-COM-09).
 *
 * **The messages are German, unlike every other exception in this
 * repository, because these are read by participants** (ADR-0001):
 * `PostMessage` is the server-side enforcement of rules the compose box
 * also shows, and spec §12 item 3 requires the refusal of the sixth message
 * to reach the person in their own language. The surface (Task 5) turns one
 * of these into a validation error on the compose field; the sentence it
 * shows is the one written here, so there is exactly one copy of it.
 *
 * `muted()` is the load-bearing one: D-COM-08 requires the muted person to
 * be told **the reason and the remaining time** — "Du kannst 10 Minuten
 * nicht schreiben: Spam". A silent mute reads as a broken app and produces
 * a support request instead of a behaviour change.
 */
final class ShoutboxWriteException extends RuntimeException
{
    /**
     * The room is locked: readable, not writable, and reversible (spec §6).
     */
    public static function roomLocked(): self
    {
        return new self('Die Wall ist gerade gesperrt. Du kannst im Moment nichts schreiben.');
    }

    /**
     * The room is closed — `EventConcluded` set `closed_at` (spec §4.1).
     * A closed room is readable until the sweep takes it; it never becomes
     * writable again, which is why this says something different from a
     * lock.
     */
    public static function roomClosed(): self
    {
        return new self('Die Wall zu diesem Event ist beendet. Du kannst hier nichts mehr schreiben.');
    }

    /**
     * Names the reason and the remaining time (D-COM-08).
     *
     * `$expiresAt` null is the indefinite mute of spec §4.3 — "for the rest
     * of the event" — and says so rather than printing a number it does not
     * have. Typed `?CarbonInterface`, never `?Carbon`: `Date::use(
     * CarbonImmutable::class)` is application-wide, so every instant this
     * application hands out is a `CarbonImmutable`, which is a sibling of
     * `Illuminate\Support\Carbon` and not a subclass (GitLab #312).
     */
    public static function muted(string $reason, ?CarbonInterface $expiresAt, CarbonInterface $now): self
    {
        $span = $expiresAt === null
            ? 'bis zum Ende des Events'
            : self::germanSpan(max(1, (int) ceil($now->diffInSeconds($expiresAt, absolute: true))));

        return new self(sprintf('Du kannst %s nicht schreiben: %s', $span, $reason));
    }

    /**
     * The sixth message in ten seconds (D-COM-09, spec §12 item 3).
     */
    public static function tooManyMessages(int $retryAfterSeconds): self
    {
        return new self(sprintf(
            'Du schreibst zu schnell. Versuch es in %s noch einmal.',
            self::germanSpan(max(1, $retryAfterSeconds)),
        ));
    }

    /**
     * The 500-character cap of spec §4.2, refused before the column can
     * refuse it — a `varchar(500)` overflow is a 500, not an answer.
     */
    public static function messageTooLong(int $maximum): self
    {
        return new self(sprintf('Deine Nachricht ist zu lang: maximal %d Zeichen.', $maximum));
    }

    public static function messageEmpty(): self
    {
        return new self('Deine Nachricht ist leer.');
    }

    /**
     * A duration a participant reads, in whole minutes once there is at
     * least one, singular where German needs it. Rounded **up**, so
     * somebody told "1 Minute" is never able to post before it has passed.
     */
    private static function germanSpan(int $seconds): string
    {
        if ($seconds < 60) {
            return $seconds === 1 ? '1 Sekunde' : sprintf('%d Sekunden', $seconds);
        }

        $minutes = (int) ceil($seconds / 60);

        return $minutes === 1 ? '1 Minute' : sprintf('%d Minuten', $minutes);
    }
}
