<?php

declare(strict_types=1);

namespace Konventix\Event\Enums;

/**
 * The event-level re-entry policy knob (decision log Round 6, D-ADM-03):
 * unlimited by default, the EVENT carries the policy — checkpoints stay
 * policy-free. Admission's validity evaluation and the offline client's
 * duplicate-scan handling (ADR-0033) read this, never a per-checkpoint
 * setting.
 */
enum ReentryPolicy: string
{
    case OnceOnly = 'once_only';
    case OncePerDay = 'once_per_day';
    case Unlimited = 'unlimited';
}
