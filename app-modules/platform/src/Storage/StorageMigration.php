<?php

declare(strict_types=1);

namespace Konventix\Platform\Storage;

/**
 * A module's stored files, described well enough for `storage:migrate` to
 * move them between disks (ADR-0043).
 *
 * Moving files is not enough on its own: every record remembers the disk it
 * was written to, which is what lets a half-migrated library keep rendering.
 * So the module — the only code allowed to touch its own models (ADR-0004) —
 * both enumerates its records and rewrites their disk once the copy is
 * verified. Platform owns the copying, never another module's tables.
 *
 * Registered from the owning module's ServiceProvider through
 * `registerStorageMigrations()`, like block types and GDPR handlers.
 */
interface StorageMigration
{
    /**
     * Short purpose key, matching the `filesystems.<key>_disk` pointer:
     * `media`, `invoices`.
     */
    public function key(): string;

    /**
     * The disk new files of this purpose are currently written to.
     */
    public function configuredDisk(): string;

    /**
     * Every record whose files still live on `$disk`.
     *
     * @return iterable<int, StorageRecord>
     */
    public function recordsOn(string $disk): iterable;

    /**
     * Every disk name this module's records currently point at, with how many
     * point at it. A name that is no longer configured means unreadable
     * files — `storage:doctor` reports it.
     *
     * @return array<string, int> disk name => record count
     */
    public function disksInUse(): array;

    /**
     * Point a record at the disk its files now live on. Called only after
     * every one of its paths has been copied and verified.
     */
    public function markMoved(string $recordId, string $disk): void;
}
