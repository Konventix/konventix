<?php

declare(strict_types=1);

namespace Konventix\Registration\Enums;

/**
 * Waitlist entry lifecycle (decision log Round 7, D-REG-12):
 * queued → offered → converted / expired / withdrawn. An offer HOLDS the
 * type's quota units (consumed at promotion, released on expiry), so an
 * offered spot cannot be sniped by a walk-up submission.
 */
enum WaitlistState: string
{
    case Queued = 'queued';
    case Offered = 'offered';
    case Converted = 'converted';
    case Expired = 'expired';
    case Withdrawn = 'withdrawn';
}
