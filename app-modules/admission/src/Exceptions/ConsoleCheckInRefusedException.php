<?php

declare(strict_types=1);

namespace Konventix\Admission\Exceptions;

use RuntimeException;

/**
 * The operator console refused a check-in before anything was written
 * (attendee-list design §4.3): the ticket is already in, or not in for a
 * check-out, its door checks were not all answered, an answer named a check
 * the ticket does not owe, or the ticket belongs to another event.
 *
 * None of these has a door equivalent — the door has no "already in" rule
 * and its token names its own event — so there is nothing door-shaped to
 * record, and no `check_ins` row or audit entry is written. A refusal the
 * door *would* also give (revoked, wrong day, no re-entry) is not this
 * exception: it is a rejected `CheckIn` from the shared core, exactly as at
 * the door.
 *
 * The message is the sentence the console shows, in English (ADR-0001).
 * `reason` is a short stable code for callers and tests.
 */
final class ConsoleCheckInRefusedException extends RuntimeException
{
    public const ALREADY_IN = 'already_in';

    public const NOT_IN = 'not_in';

    public const CHECKS_UNANSWERED = 'checks_unanswered';

    public const CHECKS_INVALID = 'checks_invalid';

    public const WRONG_EVENT = 'wrong_event';

    public function __construct(
        public readonly string $reason,
        string $sentence,
    ) {
        parent::__construct($sentence);
    }

    public static function alreadyIn(): self
    {
        return new self(self::ALREADY_IN, 'This ticket is already checked in.');
    }

    public static function notIn(): self
    {
        return new self(self::NOT_IN, 'This ticket is not checked in, so it cannot be checked out.');
    }

    /**
     * @param  list<string>  $labels
     */
    public static function checksUnanswered(array $labels): self
    {
        return new self(self::CHECKS_UNANSWERED, 'Answer every door check before checking this ticket in: '.implode(', ', $labels).'.');
    }

    public static function checksInvalid(): self
    {
        return new self(self::CHECKS_INVALID, 'The door check answers do not match the checks this ticket owes. Reload the ticket and try again.');
    }

    public static function wrongEvent(string $sentence): self
    {
        return new self(self::WRONG_EVENT, $sentence);
    }
}
