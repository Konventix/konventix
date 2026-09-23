<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Why an entrant stopped being part of the field (design §5.4, §5.5).
 *
 * A closed set rather than free text: the console groups by it, the
 * participant page explains it in the reader's language, and `EntrantWithdrawn` carries it
 * as a scalar to consumers that have no way to parse a sentence.
 *
 * `IncompleteRoster` is the one the organizer never types — it is written by
 * `DeclineIncompleteEntrant` when a roster that never filled up is dropped at
 * signup close, and it is the reason a slot comes free for somebody else.
 */
enum WithdrawalReason: string
{
    case IncompleteRoster = 'incomplete_roster';
    case CaptainWithdrew = 'captain_withdrew';
    case OrganizerWithdrew = 'organizer_withdrew';
    case Disqualified = 'disqualified';

    /** In the reader's locale (ADR-0068); the English is the catalog key. */
    public function label(): string
    {
        return match ($this) {
            self::IncompleteRoster => __('The team was not complete when signup closed.'),
            self::CaptainWithdrew => __('The team withdrew itself.'),
            self::OrganizerWithdrew => __('The organizers withdrew the team.'),
            self::Disqualified => __('The team was disqualified.'),
        };
    }
}
