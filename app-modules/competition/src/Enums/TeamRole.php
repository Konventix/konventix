<?php

declare(strict_types=1);

namespace Konventix\Competition\Enums;

/**
 * A person's standing on a team (design §5.2).
 *
 * Exactly one captain per team, assigned at creation. When the captain
 * leaves, captaincy rotates to the longest-standing remaining member —
 * LanCore's rule, kept, because the alternative (a team with nobody able to
 * regenerate the invite code or withdraw) is a support ticket every time.
 *
 * A solo entrant is a team of one whose only member is captain (D-CMP-13):
 * one shape, so nothing downstream has to ask whether a roster has a leader.
 */
enum TeamRole: string
{
    case Captain = 'captain';
    case Member = 'member';
}
