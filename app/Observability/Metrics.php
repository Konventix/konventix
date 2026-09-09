<?php

declare(strict_types=1);

namespace App\Observability;

use Closure;
use InvalidArgumentException;
use Prometheus\CollectorRegistry;

/**
 * Event-time writes into the shared registry (ADR-0059): a counter
 * increment when an order is placed, a histogram observation when a
 * webhook returns. Scrape-time values are gauges and go through the
 * package's `Prometheus::addGauge()` closures instead.
 *
 * Labels are passed by name and ordered here, because promphp wants them
 * positional in the order the metric was registered with; a caller that
 * swapped two labels would otherwise write a silently wrong series.
 */
final class Metrics
{
    /**
     * The registry is scoped (Octane rebuilds it per request) while this
     * service is a singleton, so it is resolved on every call, never pinned.
     *
     * @param  Closure(): CollectorRegistry  $registry
     */
    public function __construct(
        private readonly MetricCatalog $catalog,
        private readonly Closure $registry,
    ) {}

    /** @param array<string, string> $labels */
    public function increment(string $name, array $labels = [], float $by = 1.0): void
    {
        $definition = $this->expect($name, MetricKind::Counter);

        ($this->registry)()
            ->getOrRegisterCounter('konventix', $definition->name, $definition->help, $definition->labels)
            ->incBy($by, $this->orderedValues($definition, $labels));
    }

    /** @param array<string, string> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $definition = $this->expect($name, MetricKind::Histogram);

        ($this->registry)()
            ->getOrRegisterHistogram('konventix', $definition->name, $definition->help, $definition->labels, $definition->buckets)
            ->observe($value, $this->orderedValues($definition, $labels));
    }

    private function expect(string $name, MetricKind $kind): MetricDefinition
    {
        $definition = $this->catalog->get($name);

        if ($definition->kind !== $kind) {
            throw new InvalidArgumentException(sprintf('Metric [%s] is a %s, not a %s.', $name, $definition->kind->value, $kind->value));
        }

        return $definition;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<string>
     */
    private function orderedValues(MetricDefinition $definition, array $labels): array
    {
        $unknown = array_diff(array_keys($labels), $definition->labels);

        if ($unknown !== []) {
            throw new InvalidArgumentException(sprintf('Metric [%s] has no label [%s].', $definition->name, implode(', ', $unknown)));
        }

        $values = [];

        foreach ($definition->labels as $label) {
            if (! array_key_exists($label, $labels)) {
                throw new InvalidArgumentException(sprintf('Metric [%s] needs a value for label [%s].', $definition->name, $label));
            }

            $values[] = (string) $labels[$label];
        }

        return $values;
    }
}
