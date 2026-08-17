<?php

declare(strict_types=1);

namespace Konventix\Platform\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Storage;

/**
 * Deletes a deleted brand's media files (ADR-0043).
 *
 * Rows cascade when a brand goes; files never did. On a local disk that left
 * a directory nobody looked at, on object storage it leaves objects the
 * operator keeps paying for — and media is brand-sharded by prefix
 * (`{brandId}/…`), so the whole prefix is exactly the brand's own files and
 * nothing else.
 *
 * The disks are passed in rather than read from config: the brand's items may
 * have been spread across disks by a partial migration, and by the time this
 * runs their rows are gone. They are collected while the brand still exists.
 *
 * The invoice archive is deliberately NOT touched. Statutory retention
 * outlives the brand (ADR-0031); a deleted brand is not a reason to destroy
 * bookkeeping records.
 */
final class PurgeBrandMedia implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /**
     * @param  list<string>  $disks
     */
    public function __construct(
        public readonly string $brandId,
        public readonly array $disks,
    ) {}

    public function handle(): void
    {
        foreach ($this->disks as $disk) {
            if (config("filesystems.disks.{$disk}") === null) {
                continue;
            }

            Storage::disk($disk)->deleteDirectory($this->brandId);
        }
    }
}
