<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * Where an entrant stands in a competition.
 *
 * `CheckedIn` is distinct from `Registered` because D-CMP-05 splits the two
 * gates: a confirmed Registration suffices to *enter*, while admission
 * possession is verified at check-in or match start. Competition records that
 * the check happened; it never performs it.
 */
enum EntrantState: string
{
    /**
     * A roster below the discipline's `team_size` (D-CMP-17, design §5.4).
     * It holds a slot against the participant limit — otherwise the fill-up
     * at signup close would have nothing to fill — and `start` refuses while
     * one exists. The roster Actions that move an entrant in and out of this
     * state arrive with §5's own slice; the lifecycle only has to refuse.
     */
    case Incomplete = 'incomplete';

    case Registered = 'registered';
    case CheckedIn = 'checked_in';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';
}
