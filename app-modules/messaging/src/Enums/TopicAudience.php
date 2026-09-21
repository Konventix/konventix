<?php

declare(strict_types=1);

namespace Konventix\Messaging\Enums;

/**
 * Who a topic speaks to (bugfix round 2026-09-21, Task 9). The in-app inbox
 * has two homes — the bell on a brand host and the bell in the operator
 * console — and a notice belongs in exactly one of them: an organizer who is
 * also a registrant must not find console links in their participant inbox,
 * and the console bell must not be empty by design.
 *
 * The audience also decides who is offered the topic on the preferences
 * page: an account that cannot reach the console is never asked whether it
 * wants console notices.
 */
enum TopicAudience: string
{
    /** Anyone with an account on a brand — read on the brand host. */
    case Participant = 'participant';

    /** People who run the shop or the event — read on the operator's admin host. */
    case Operator = 'operator';
}
