<?php

declare(strict_types=1);

namespace Konventix\Platform\Observability;

use RuntimeException;

final class UnknownMetric extends RuntimeException
{
    public static function named(string $name): self
    {
        return new self(sprintf('Metric [%s] is not in the catalogue. Declare it with MetricCatalog::register() in the owning provider.', $name));
    }
}
