<?php

declare(strict_types=1);

namespace Konventix\Event\Enums;

/**
 * The per-event half of ADR-0064: whether the landing page may show who is
 * coming. `off` shows nothing; `members` shows the count to everyone and
 * names to signed-in accounts; `public` shows names to anyone. The other
 * half — whether a given person may be named at all — is the person's own
 * opt-in on `users.listed_in_attendees`, and both must agree before a name
 * appears. Carried as this enum's scalar value on
 * `Events\EventAttendeeListPolicyChanged`, because Registration renders the
 * list and may not import this enum (ADR-0004).
 */
enum AttendeeListVisibility: string
{
    case Off = 'off';
    case Members = 'members';
    case Public = 'public';
}
