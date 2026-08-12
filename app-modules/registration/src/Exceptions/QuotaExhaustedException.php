<?php

declare(strict_types=1);

namespace Konventix\Registration\Exceptions;

use Konventix\Registration\Models\Quota;
use RuntimeException;

/**
 * Thrown by `SubmitRegistration` when one of the `RegistrationType`'s quotas
 * has no remaining capacity. Nothing is consumed and no registration is
 * created — the guard runs before any write in the transaction.
 */
final class QuotaExhaustedException extends RuntimeException
{
    public static function forQuota(Quota $quota): self
    {
        return new self(sprintf(
            'Quota [%s] ("%s") has no remaining capacity (%d/%d consumed).',
            $quota->id,
            $quota->name,
            $quota->consumed,
            $quota->capacity,
        ));
    }
}
