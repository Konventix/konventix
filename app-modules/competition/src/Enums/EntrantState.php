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
    case Registered = 'registered';
    case CheckedIn = 'checked_in';
    case Withdrawn = 'withdrawn';
    case Disqualified = 'disqualified';
}
