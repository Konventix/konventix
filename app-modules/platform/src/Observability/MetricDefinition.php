<?php

declare(strict_types=1);

namespace Konventix\Platform\Observability;

use InvalidArgumentException;

/**
 * One line of the metric catalogue (ADR-0059): what the application emits,
 * under which labels, so the coverage test can hold dashboards and rules
 * to it. `name` is without the `konventix_` namespace; the renderer adds it.
 *
 * @param  list<string>  $labels
 * @param  list<float>|null  $buckets  histogram buckets, seconds
 */
final readonly class MetricDefinition
{
    /**
     * @param  array<int, string>  $labels
     * @param  array<int, float>|null  $buckets
     */
    public function __construct(
        public string $name,
        public MetricKind $kind,
        public string $help,
        public array $labels = [],
        public ?array $buckets = null,
    ) {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException(sprintf('Metric name [%s] must be snake_case ascii.', $name));
        }

        if (str_starts_with($name, 'konventix_')) {
            throw new InvalidArgumentException(sprintf('Metric name [%s] carries the konventix_ namespace; the renderer adds it.', $name));
        }

        if ($kind !== MetricKind::Histogram && $buckets !== null) {
            throw new InvalidArgumentException(sprintf('Only a histogram has buckets; [%s] is a %s.', $name, $kind->value));
        }
    }

    public function fullName(): string
    {
        return 'konventix_'.$this->name;
    }
}
