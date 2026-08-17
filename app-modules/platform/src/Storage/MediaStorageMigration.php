<?php

declare(strict_types=1);

namespace Konventix\Platform\Storage;

use Konventix\Platform\Models\MediaItem;

/**
 * The media library's side of `storage:migrate` (ADR-0043): every item on a
 * given disk, with its original and every generated variant, and the row
 * rewrite that follows a verified copy.
 *
 * Cross-brand by design — a storage migration is an operator-wide operation
 * run from the console, where no brand is ambient (ADR-0029).
 */
final class MediaStorageMigration implements StorageMigration
{
    public function key(): string
    {
        return 'media';
    }

    public function configuredDisk(): string
    {
        return (string) config('filesystems.media_disk', 'media');
    }

    /**
     * @return iterable<int, StorageRecord>
     */
    public function recordsOn(string $disk): iterable
    {
        foreach (MediaItem::withoutBrandScope()->where('disk', $disk)->lazyById(100) as $item) {
            yield new StorageRecord(
                id: $item->getKey(),
                paths: [$item->path, ...array_values($item->variants)],
                label: $item->original_filename,
            );
        }
    }

    public function markMoved(string $recordId, string $disk): void
    {
        MediaItem::withoutBrandScope()
            ->whereKey($recordId)
            ->update(['disk' => $disk]);
    }
}
