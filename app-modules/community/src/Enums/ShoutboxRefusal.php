<?php

declare(strict_types=1);

namespace Konventix\Community\Enums;

/**
 * Why a shoutbox write was refused, in a form a surface can branch on
 * (spec §6, D-COM-08, D-COM-09).
 *
 * **`ShoutboxWriteException`'s sentence is German prose for a participant
 * to read; this is the machine-readable half, and no surface should ever
 * parse the former to work out the latter.** ADR-0001 keeps the German in
 * exactly one place — the exception — which only works if the code that
 * renders it has another way to ask what happened.
 *
 * `field()` is that mapping made explicit: the room page turns a refusal
 * into a `ValidationException` on the form field the refusal belongs to,
 * and every case here names one. `body` is `PostMessageData`'s only field
 * and `note` is `ReportData`'s, so the names are the ones the client
 * already submitted rather than labels invented here.
 *
 * The backed values are snake_case because they cross the wire; the cases
 * are TitleCase because they are PHP.
 */
enum ShoutboxRefusal: string
{
    case RoomLocked = 'room_locked';
    case RoomClosed = 'room_closed';
    case Muted = 'muted';
    case MessageEmpty = 'message_empty';
    case MessageTooLong = 'message_too_long';
    case TooManyMessages = 'too_many_messages';
    case NoteTooLong = 'note_too_long';

    /**
     * The submitted field this refusal belongs on, so that a controller can
     * write `ValidationException::withMessages([$cause->field() => [...]])`
     * without a match of its own.
     */
    public function field(): string
    {
        return match ($this) {
            self::NoteTooLong => 'note',
            default => 'body',
        };
    }
}
