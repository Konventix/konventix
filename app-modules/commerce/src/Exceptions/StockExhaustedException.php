<?php

declare(strict_types=1);

namespace Konventix\Commerce\Exceptions;

use Konventix\Commerce\Models\ProductVariant;
use RuntimeException;

/**
 * Thrown when a consumer Action (Task 7) tries to consume more stock than a
 * `ProductVariant` has remaining. `hasStockFor()` is the check that should
 * keep this from firing in normal operation — this exception is the guard
 * for the concurrent path a `lockForUpdate()` closes.
 */
final class StockExhaustedException extends RuntimeException
{
    public static function forVariant(ProductVariant $variant): self
    {
        return new self(sprintf(
            'ProductVariant [%s] has no remaining stock (%d of %d consumed).',
            $variant->getKey(),
            $variant->stock_consumed,
            $variant->stock_capacity,
        ));
    }
}
