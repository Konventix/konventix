<?php

declare(strict_types=1);

namespace Konventix\Platform\Storage;

use InvalidArgumentException;

/**
 * The registry `registerStorageMigrations()` feeds (ADR-0003), mirroring
 * BlockRegistry and Compliance's GdprHandlerRegistry. Bound as a singleton;
 * every module registers its own {@see StorageMigration} from its own
 * ServiceProvider.
 *
 * Holds only boot-time registrations, so a long-lived Octane worker may
 * share it across requests.
 */
class StorageMigrationRegistry
{
    /** @var array<string, StorageMigration> purpose key => migration */
    private array $migrations = [];

    public function register(StorageMigration $migration): void
    {
        if (isset($this->migrations[$migration->key()])) {
            throw new InvalidArgumentException("Storage migration [{$migration->key()}] is already registered.");
        }

        $this->migrations[$migration->key()] = $migration;
    }

    /**
     * @return array<string, StorageMigration>
     */
    public function all(): array
    {
        return $this->migrations;
    }

    public function get(string $key): ?StorageMigration
    {
        return $this->migrations[$key] ?? null;
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->migrations);
    }
}
