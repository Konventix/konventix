<?php

declare(strict_types=1);

namespace Konventix\Platform\Http\Controllers\Admin;

use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Platform\Actions\UpdateBrandStorageSettings;
use Konventix\Platform\Data\BrandStorageSettingsData;
use Konventix\Platform\Enums\Permission;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandStorageSetting;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Models\Scopes\BrandScope;
use Konventix\Platform\Storage\DiskResolver;

/**
 * Operator console: which brands keep their media in their own bucket
 * (ADR-0043 point 6), mirroring the per-brand mail transport screen
 * (ADR-0028). Route middleware already requires the admin host, auth and
 * `platform.access-admin`; every operation here additionally requires
 * `platform.manage-storage-settings`.
 *
 * A brand without a row uses the deployment's media disk — the normal case,
 * shown as such rather than as something missing.
 */
class StorageSettingsAdminController
{
    public function index(DiskResolver $disks): Response
    {
        Gate::authorize(Permission::ManageStorageSettings->value);

        $settings = BrandStorageSetting::withoutBrandScope()->get()->keyBy('brand_id');

        return Inertia::render('admin/storage-settings/Index', [
            'deploymentDisk' => (string) config('filesystems.media_disk', 'media'),
            'brands' => Brand::query()->orderBy('name')->get(['id', 'name'])
                ->map(function (Brand $brand) use ($settings, $disks): array {
                    /** @var BrandStorageSetting|null $row */
                    $row = $settings->get((string) $brand->getKey());

                    return [
                        'id' => (string) $brand->getKey(),
                        'name' => $brand->name,
                        'bucket' => $row?->bucket,
                        'publicDirect' => (bool) $row?->public_direct,
                        // What the brand's files actually sit on today, which
                        // is not the same question as where new ones go: a
                        // migration in progress shows both.
                        'diskForNewUploads' => $disks->mediaDiskNameFor((string) $brand->getKey()),
                        'itemsInOwnBucket' => $this->itemsOnOwnBucket((string) $brand->getKey()),
                    ];
                })
                ->values()
                ->all(),
        ]);
    }

    public function edit(Brand $brand): Response
    {
        Gate::authorize(Permission::ManageStorageSettings->value);

        /** @var BrandStorageSetting|null $settings */
        $settings = BrandStorageSetting::withoutBrandScope()
            ->where('brand_id', $brand->getKey())
            ->first();

        return Inertia::render('admin/storage-settings/Edit', [
            'brand' => ['id' => (string) $brand->getKey(), 'name' => $brand->name],
            'itemsInOwnBucket' => $this->itemsOnOwnBucket((string) $brand->getKey()),
            'settings' => [
                'useOwnStorage' => $settings !== null,
                'bucket' => $settings?->bucket,
                'region' => $settings?->region,
                'endpoint' => $settings?->endpoint,
                'prefix' => $settings->prefix ?? 'media',
                'usePathStyleEndpoint' => (bool) $settings?->use_path_style_endpoint,
                'publicDirect' => (bool) $settings?->public_direct,
                'url' => $settings?->url,
                'accessKeyId' => $settings?->credentials['key'] ?? null,
                // The secret itself never travels back to the browser.
                'hasSecretAccessKey' => ($settings?->credentials['secret'] ?? null) !== null,
            ],
        ]);
    }

    public function update(Brand $brand, BrandStorageSettingsData $data, UpdateBrandStorageSettings $action): RedirectResponse
    {
        Gate::authorize(Permission::ManageStorageSettings->value);

        $action->handle((string) $brand->getKey(), $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Storage settings saved.')]);

        return to_route('admin.storage-settings.edit', $brand);
    }

    /**
     * How many of the brand's files already live in its own bucket — the
     * number that decides whether switching storage off is safe.
     */
    private function itemsOnOwnBucket(string $brandId): int
    {
        return MediaItem::withoutGlobalScope(BrandScope::class)
            ->where('brand_id', $brandId)
            ->where('disk', 'media:'.$brandId)
            ->count();
    }
}
