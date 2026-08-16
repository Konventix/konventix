<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * One live settlement account per brand (ADR-0007, enforced by a partial
 * unique index): switching providers is disconnect-then-connect, so which
 * account settles a brand's money is always a single answer.
 */
final class BrandAlreadyHasLiveSettlementAccountException extends RuntimeException
{
    public static function for(string $brandId, string $provider): self
    {
        return new self(sprintf(
            'Brand [%s] already settles through a live [%s] account; disconnect it before connecting another.',
            $brandId,
            $provider,
        ));
    }
}
