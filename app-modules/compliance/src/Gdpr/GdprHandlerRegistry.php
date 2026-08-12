<?php

declare(strict_types=1);

namespace Konventix\Compliance\Gdpr;

use Illuminate\Support\Collection;

/**
 * The registry `registerGdprExporters()` feeds (ADR-0003), mirroring how
 * Platform's NavigationRegistry is registered into. Bound as a singleton in
 * ComplianceServiceProvider; every module registers its own GdprHandler
 * instance from its own ServiceProvider — never here, and never by
 * Compliance reaching into another module.
 *
 * Compliance uses this to know which modules are addressed by a subject
 * request's fan-out: OpenSubjectRequest seeds one pending
 * DataSubjectRequestResponse row per currently registered module. Later,
 * export assembly (TODO.md backlog) will loop over `all()` to collect each
 * handler's contribution.
 */
class GdprHandlerRegistry
{
    /** @var array<string, GdprHandler> module => handler */
    private array $handlers = [];

    public function register(GdprHandler $handler): void
    {
        $this->handlers[$handler->module()] = $handler;
    }

    public function has(string $module): bool
    {
        return array_key_exists($module, $this->handlers);
    }

    public function get(string $module): ?GdprHandler
    {
        return $this->handlers[$module] ?? null;
    }

    /**
     * @return array<int, string>
     */
    public function moduleNames(): array
    {
        return array_keys($this->handlers);
    }

    /**
     * @return Collection<string, GdprHandler>
     */
    public function all(): Collection
    {
        return Collection::make($this->handlers);
    }
}
