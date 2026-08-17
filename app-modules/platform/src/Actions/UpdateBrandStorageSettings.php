<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\BrandStorageSettingsData;
use Konventix\Platform\Models\BrandStorageSetting;
use Konventix\Platform\Models\MediaItem;

/**
 * Writes (or clears) a brand's own object storage (ADR-0043 point 6). Runs on
 * the admin host where BrandScope hardens to zero rows, so every query
 * escapes the scope and the brand id is stamped explicitly.
 *
 * Clearing puts the brand back on the deployment's media disk for NEW
 * uploads and moves nothing: existing items keep the disk recorded on their
 * own row, and would stop resolving if their settings vanished — so this
 * refuses to clear while any item still lives on the brand's bucket, and
 * names `storage:migrate` as the way out.
 */
final class UpdateBrandStorageSettings
{
    public function handle(string $brandId, BrandStorageSettingsData $data): ?BrandStorageSetting
    {
        return DB::transaction(function () use ($brandId, $data): ?BrandStorageSetting {
            $settings = BrandStorageSetting::withoutBrandScope()
                ->where('brand_id', $brandId)
                ->first();

            if (! $data->useOwnStorage) {
                $this->clear($brandId, $settings);

                return null;
            }

            $secret = $data->secretAccessKey ?? ($settings?->credentials['secret'] ?? null);

            if ($secret === null) {
                throw ValidationException::withMessages([
                    'secretAccessKey' => __('A secret access key is required the first time this brand gets its own storage.'),
                ]);
            }

            if ($settings === null) {
                $settings = new BrandStorageSetting;
                $settings->forceFill(['brand_id' => $brandId]);
            }

            $settings->fill([
                'bucket' => $data->bucket,
                'region' => $data->region,
                'endpoint' => $data->endpoint,
                'prefix' => $data->prefix ?: 'media',
                'use_path_style_endpoint' => $data->usePathStyleEndpoint,
                'public_direct' => $data->publicDirect,
                'url' => $data->url,
                'credentials' => ['key' => (string) $data->accessKeyId, 'secret' => $secret],
            ])->save();

            return $settings;
        });
    }

    private function clear(string $brandId, ?BrandStorageSetting $settings): void
    {
        if ($settings === null) {
            return;
        }

        $remaining = MediaItem::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('disk', 'media:'.$brandId)
            ->count();

        if ($remaining > 0) {
            throw ValidationException::withMessages([
                'useOwnStorage' => __(
                    ':count file(s) still live in this brand\'s own bucket. Move them first with `storage:migrate`, or they become unreadable.',
                    ['count' => $remaining],
                ),
            ]);
        }

        $settings->delete();
    }
}
