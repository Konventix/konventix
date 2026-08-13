<?php

declare(strict_types=1);

namespace Konventix\Content\Enums;

/**
 * How loud an announcement is (D-CNT-08).
 *
 * Content declares intent only — **Messaging alone** maps it to channels and
 * timing, where `urgent` bypasses digests and quiet hours. Content must never
 * decide that itself, or the same announcement would mean different things
 * depending on which module rendered it.
 */
enum Urgency: string
{
    case Normal = 'normal';
    case Urgent = 'urgent';
}
