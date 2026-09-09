<?php

declare(strict_types=1);

namespace Konventix\Platform\Observability;

use Closure;
use InvalidArgumentException;
use Prometheus\CollectorRegistry;
use Throwable;

/**
 * Event-time writes into the shared registry (ADR-0059): a counter
 * increment when an order is placed, a histogram observation when a
 * webhook returns. Scrape-time values are gauges and go through the
 * package's `Prometheus::addGauge()` closures instead.
 *
 * Labels are passed by name and ordered here, because promphp wants them
 * positional in the order the metric was registered with; a caller that
 * swapped two labels would otherwise write a silently wrong series.
 *
 * Recording must never break the request or job it observes: `increment()`
 * and `observe()` still throw on a programmer error — an undeclared metric,
 * the wrong kind, a missing or unknown label — since those are caught by
 * `MetricsRegistryTest` and want to fail loudly in development. The actual
 * registry write (a Redis round-trip under `prometheus.storage=redis`) is
 * different: a storage failure there is an observability problem, not the
 * caller's, so it is reported and swallowed rather than raised into
 * whatever placed an order, recorded a payment or admitted a waiting-room
 * ticket. Every call site stays plain — the containment lives here once,
 * not repeated at every listener.
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
        $values = $this->orderedValues($definition, $labels);

        $this->write(function () use ($definition, $by, $values): void {
            ($this->registry)()
                ->getOrRegisterCounter('konventix', $definition->name, $definition->help, $definition->labels)
                ->incBy($by, $values);
        });
    }

    /** @param array<string, string> $labels */
    public function observe(string $name, float $value, array $labels = []): void
    {
        $definition = $this->expect($name, MetricKind::Histogram);
        $values = $this->orderedValues($definition, $labels);

        $this->write(function () use ($definition, $value, $values): void {
            ($this->registry)()
                ->getOrRegisterHistogram('konventix', $definition->name, $definition->help, $definition->labels, $definition->buckets)
                ->observe($value, $values);
        });
    }

    /**
     * The one place a registry write can fail — a Redis error under
     * `prometheus.storage=redis`, an adapter outage. Reported, never
     * raised: the catalogue lookup and label validation above still throw
     * on a programmer error, but a storage failure belongs to observability,
     * not to whatever this call was counting.
     */
    private function write(Closure $write): void
    {
        try {
            $write();
        } catch (Throwable $e) {
            report($e);
        }
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
