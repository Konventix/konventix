<?php

declare(strict_types=1);

namespace Konventix\Platform\Operators;

use Closure;

/**
 * Where the console's event selector gets its options. Platform owns the
 * selector and may not read Event's tables (ADR-0004), so the module that
 * owns events registers a source from its own provider
 * (`ModuleServiceProvider::registerConsoleEventSources()`), the way slots
 * and block types are contributed. No source registered means no
 * selector — a build without the Event module simply has none.
 *
 * Singleton: holds closures registered at boot and nothing else.
 */
final class ConsoleEventSources
{
    /** @var list<Closure(?string): list<array{id: string, name: string, brandId: string}>> */
    private array $sources = [];

    /**
     * @param  Closure(?string $brandId): list<array{id: string, name: string, brandId: string}>  $source
     */
    public function add(Closure $source): void
    {
        $this->sources[] = $source;
    }

    public function hasAny(): bool
    {
        return $this->sources !== [];
    }

    /**
     * @return list<array{id: string, name: string, brandId: string}>
     */
    public function eventsFor(?string $brandId): array
    {
        $events = [];

        foreach ($this->sources as $source) {
            $events = [...$events, ...$source($brandId)];
        }

        return $events;
    }
}
