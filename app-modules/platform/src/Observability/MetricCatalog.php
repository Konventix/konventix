<?php

declare(strict_types=1);

namespace Konventix\Platform\Observability;

use InvalidArgumentException;

/**
 * The declared list of every metric the application emits (ADR-0059). The
 * host registers its own entries in ObservabilityServiceProvider; a module
 * registers its entries in its own provider (ADR-0003). `Metrics` refuses a
 * name that is not here, and ObservabilityCoverageTest holds every
 * dashboard and rule to this list.
 */
final class MetricCatalog
{
    /** @var array<string, MetricDefinition> */
    private array $definitions = [];

    public function register(MetricDefinition ...$definitions): void
    {
        foreach ($definitions as $definition) {
            if (isset($this->definitions[$definition->name])) {
                throw new InvalidArgumentException(sprintf('Metric [%s] is already in the catalogue.', $definition->name));
            }

            $this->definitions[$definition->name] = $definition;
        }
    }

    public function has(string $name): bool
    {
        return isset($this->definitions[$name]);
    }

    public function get(string $name): MetricDefinition
    {
        return $this->definitions[$name] ?? throw UnknownMetric::named($name);
    }

    /** @return list<MetricDefinition> */
    public function all(): array
    {
        return array_values($this->definitions);
    }
}
