<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\MediaUsage;

/**
 * Deletes a media item and its stored files — refused while any usage
 * reference exists (ADR-0035: usage references make cleanup *possible*;
 * deleting under a live reference would break the referencing entity).
 */
final class DeleteMediaItem
{
    public function handle(MediaItem $item): void
    {
        DB::transaction(function () use ($item): void {
            // Explicitly cross-brand (ADR-0010): deletion runs on the
            // operator host, where BrandScope hardens to nothing — a scoped
            // count would read 0 and delete referenced files.
            $usages = MediaUsage::withoutBrandScope()
                ->where('media_item_id', $item->getKey())
                ->count();

            if ($usages > 0) {
                throw ValidationException::withMessages([
                    'media' => __(':count entities still reference this file — remove those references first.', ['count' => $usages]),
                ]);
            }

            $paths = [$item->path, ...array_values($item->variants)];

            $item->delete();

            Storage::disk($item->disk)->delete($paths);
        });
    }
}
