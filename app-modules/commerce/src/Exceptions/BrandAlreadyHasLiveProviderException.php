<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use RuntimeException;

/**
 * One live settlement account per provider (ADR-0046, enforced by a partial
 * unique index on `(brand_id, provider)`): a brand may connect Stripe and
 * Mollie at once, but never two live accounts of the same provider.
 */
final class BrandAlreadyHasLiveProviderException extends RuntimeException
{
    public static function for(string $brandId, string $provider): self
    {
        return new self(sprintf(
            'Brand [%s] already settles through [%s]; disconnect it before connecting another %s account. '
            .'One live account per provider (ADR-0046) — the single-answer rule of slice 7 is reversed on purpose.',
            $brandId,
            $provider,
            $provider,
        ));
    }
}
