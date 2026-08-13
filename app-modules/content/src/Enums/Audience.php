<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * Who may read an entry (D-CNT-07).
 *
 * Exactly two values at release, deliberately: participant-gating would
 * couple Content to Registration state, which the decision rejects. A page
 * for confirmed attendees only is not expressible here on purpose.
 */
enum Audience: string
{
    case Everyone = 'everyone';
    case LoggedIn = 'logged-in';
}
