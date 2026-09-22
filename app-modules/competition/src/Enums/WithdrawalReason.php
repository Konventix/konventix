<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Why an entrant stopped being part of the field (design §5.4, §5.5).
 *
 * A closed set rather than free text: the console groups by it, the
 * participant page explains it in German, and `EntrantWithdrawn` carries it
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

    /** Participant-facing copy is German (ADR-0001). */
    public function label(): string
    {
        return match ($this) {
            self::IncompleteRoster => __('Das Team war beim Anmeldeschluss nicht vollzählig.'),
            self::CaptainWithdrew => __('Das Team hat sich selbst zurückgezogen.'),
            self::OrganizerWithdrew => __('Die Orga hat das Team zurückgezogen.'),
            self::Disqualified => __('Das Team wurde disqualifiziert.'),
        };
    }
}
