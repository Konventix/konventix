<?php

declare(strict_types=1);

namespace Konventix\Admission\Enums;

/**
 * Whether a scan admitted the holder. Both outcomes are recorded — the
 * Admission backlog asks for "a full accepted/rejected audit trail", so a
 * rejection is a row, not a discarded error.
 */
enum CheckInResult: string
{
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
