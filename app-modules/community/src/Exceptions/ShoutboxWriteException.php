<?php

declare(strict_types=1);

namespace Konventix\Community\Exceptions;

use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Konventix\Community\Enums\ShoutboxRefusal;
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
 * **`$cause` and `$retryAfterSeconds` are the machine-readable half, and
 * they exist so that nothing ever parses the German.** A sentence a
 * translator may reword is not a protocol: the surface branches on
 * `ShoutboxRefusal`, renders a countdown from `$retryAfterSeconds`, and
 * puts the sentence itself on the field `$cause->field()` names. Every
 * refusal carries a cause; `$retryAfterSeconds` is null wherever there is
 * nothing to count down — a lock, a closed room, a body that is too long,
 * and an indefinite mute, which has no end to name.
 *
 * `muted()` is the load-bearing one: D-COM-08 requires the muted person to
 * be told **the reason and the remaining time** — "Du kannst 10 Minuten
 * nicht schreiben: Spam". A silent mute reads as a broken app and produces
 * a support request instead of a behaviour change.
 *
 * The constructor is private: a refusal without its cause would be exactly
 * the German-only exception this class stopped being, so the named
 * constructors are the only way in.
 */
final class ShoutboxWriteException extends RuntimeException
{
    private function __construct(
        public readonly ShoutboxRefusal $cause,
        string $message,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }

    /**
     * The room is locked: readable, not writable, and reversible (spec §6).
     */
    public static function roomLocked(): self
    {
        return new self(
            ShoutboxRefusal::RoomLocked,
            'Die Wall ist gerade gesperrt. Du kannst im Moment nichts schreiben.',
        );
    }

    /**
     * The room is closed — `EventConcluded` set `closed_at` (spec §4.1).
     * A closed room is readable until the sweep takes it; it never becomes
     * writable again, which is why this says something different from a
     * lock.
     */
    public static function roomClosed(): self
    {
        return new self(
            ShoutboxRefusal::RoomClosed,
            'Die Wall zu diesem Event ist beendet. Du kannst hier nichts mehr schreiben.',
        );
    }

    /**
     * Names the reason and the remaining time (D-COM-08).
     *
     * `$expiresAt` null is the indefinite mute of spec §4.3 — "for the rest
     * of the event" — and says so rather than printing a number it does not
     * have; `$retryAfterSeconds` is null in that case for the same reason,
     * because there is no moment to count down to. Typed `?CarbonInterface`,
     * never `?Carbon`: `Date::use(CarbonImmutable::class)` is
     * application-wide, so every instant this application hands out is a
     * `CarbonImmutable`, which is a sibling of `Illuminate\Support\Carbon`
     * and not a subclass (GitLab #312).
     */
    public static function muted(string $reason, ?CarbonInterface $expiresAt, CarbonInterface $now): self
    {
        $remaining = $expiresAt === null
            ? null
            : max(1, (int) ceil($now->diffInSeconds($expiresAt, absolute: true)));

        $span = $remaining === null ? 'bis zum Ende des Events' : self::germanSpan($remaining);

        return new self(
            ShoutboxRefusal::Muted,
            sprintf('Du kannst %s nicht schreiben: %s', $span, $reason),
            $remaining,
        );
    }

    /**
     * The sixth message in ten seconds (D-COM-09, spec §12 item 3). The
     * countdown the compose box shows comes from `$retryAfterSeconds`, not
     * from reading the sentence back.
     */
    public static function tooManyMessages(int $retryAfterSeconds): self
    {
        $seconds = max(1, $retryAfterSeconds);

        return new self(
            ShoutboxRefusal::TooManyMessages,
            sprintf('Du schreibst zu schnell. Versuch es in %s noch einmal.', self::germanSpan($seconds)),
            $seconds,
        );
    }

    /**
     * The 500-character cap of spec §4.2, refused before the column can
     * refuse it — a `varchar(500)` overflow is a 500, not an answer.
     */
    public static function messageTooLong(int $maximum): self
    {
        return new self(
            ShoutboxRefusal::MessageTooLong,
            sprintf('Deine Nachricht ist zu lang: maximal %d Zeichen.', $maximum),
        );
    }

    public static function messageEmpty(): self
    {
        return new self(ShoutboxRefusal::MessageEmpty, 'Deine Nachricht ist leer.');
    }

    /**
     * The reporter's optional note against `community_message_reports.note`'s
     * own `varchar(255)` (spec §4.4). A participant reaches this one as
     * directly as the body cap — a client that ignores the form's `maxlength`
     * must get an answer rather than a constraint violation.
     */
    public static function noteTooLong(int $maximum): self
    {
        return new self(
            ShoutboxRefusal::NoteTooLong,
            sprintf('Dein Hinweis ist zu lang: maximal %d Zeichen.', $maximum),
        );
    }

    /**
     * The refusal as the compose box receives it: a validation error on the
     * field `$cause->field()` names, carrying this sentence and nothing
     * parsed out of it (see the class docblock), plus the two
     * machine-readable halves — `cause` to branch on and
     * `retryAfterSeconds` for the countdown a timed mute or the rate limit
     * shows.
     *
     * A `ValidationException` so that every surface handles a refusal the
     * way it handles any invalid input; its response is set explicitly only
     * because the countdown's number has no place in Laravel's default
     * validation body.
     */
    public function toValidationException(): ValidationException
    {
        $exception = ValidationException::withMessages([$this->cause->field() => [$this->getMessage()]]);
        $exception->response = $this->toJsonResponse(422);

        return $exception;
    }

    /**
     * The body `toValidationException()` answers with, at any status —
     * `CommunityServiceProvider`'s `shoutbox-post` limiter answers a
     * flood with this same body at 429, so the compose box reads one shape
     * whichever of the two halves of D-COM-09 refused the post.
     */
    public function toJsonResponse(int $status): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->getMessage(),
            'errors' => [$this->cause->field() => [$this->getMessage()]],
            'cause' => $this->cause->value,
            'retryAfterSeconds' => $this->retryAfterSeconds,
        ], $status);
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
