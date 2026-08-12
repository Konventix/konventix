<?php

declare(strict_types=1);

namespace Konventix\Platform\Blocks;

use InvalidArgumentException;
use Konventix\Platform\Modules\ModuleGate;

/**
 * Boot-time registry of block types, filled by module ServiceProviders
 * (ADR-0003). Rendering filters through the module gate, so a page keeps a
 * block of a deactivated module in storage but never renders it — same
 * semantics as UI slots.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may share
 * it across requests; the per-request part (the gate) is resolved at call
 * time, never captured.
 */
class BlockRegistry
{
    /** @var array<string, BlockType> */
    private array $types = [];

    public function register(BlockType $type): void
    {
        if (isset($this->types[$type->key()])) {
            throw new InvalidArgumentException(
                "Block type [{$type->key()}] is already registered by module [{$this->types[$type->key()]->module()}]."
            );
        }

        $this->types[$type->key()] = $type;
    }

    /**
     * Every registered type, regardless of activation.
     *
     * @return array<string, BlockType>
     */
    public function all(): array
    {
        return $this->types;
    }

    /**
     * The type behind a stored block key, or null when the key is unknown or
     * its module is inactive for the current brand — callers skip such blocks
     * rather than failing the page.
     */
    public function resolveActive(string $key): ?BlockType
    {
        $type = $this->types[$key] ?? null;

        if ($type === null || ! app(ModuleGate::class)->isActive($type->module())) {
            return null;
        }

        return $type;
    }
}
