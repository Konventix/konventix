<?php

declare(strict_types=1);

namespace Konventix\Platform\Storage;

use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Storage;
use Konventix\Platform\Models\BrandStorageSetting;
use RuntimeException;

/**
 * Turns a stored disk *name* into a filesystem (ADR-0043 point 6).
 *
 * Two kinds of name exist. A plain one (`media`, `media_s3`) is a disk from
 * `config/filesystems.php`. A prefixed one — `media:{brandId}` — is a brand
 * that brought its own bucket, built at resolution time from that brand's
 * encrypted storage settings, the same way a brand's mail transport is built
 * from its own row (ADR-0028).
 *
 * Every media path goes through here rather than through `Storage::disk()`
 * directly, because the disk a file lives on is data on its row, and only
 * this class knows how to read both kinds of name.
 *
 * **Octane:** bound scoped, and the memo below is therefore per request.
 * Laravel's own `FilesystemManager` caches disks in a container singleton, so
 * a brand-scoped disk registered there would outlive the request that built
 * it and be handed to the next brand served by that worker. `Storage::build()`
 * deliberately sidesteps that: it returns an instance without registering it
 * under a name.
 */
class DiskResolver
{
    /** Separates the purpose from the brand ULID in a brand-scoped disk name. */
    public const string BRAND_SEPARATOR = ':';

    /** @var array<string, Filesystem> resolved this request only */
    private array $memo = [];

    /** @var array<string, array<string, mixed>|null> */
    private array $configMemo = [];

    /**
     * The disk name new media for this brand should be written to: the
     * brand's own bucket when it has one, the deployment's configured media
     * disk otherwise.
     */
    public function mediaDiskNameFor(string $brandId): string
    {
        return $this->brandSettings($brandId) !== null
            ? 'media'.self::BRAND_SEPARATOR.$brandId
            : (string) config('filesystems.media_disk', 'media');
    }

    public function isBrandDisk(string $diskName): bool
    {
        return str_contains($diskName, self::BRAND_SEPARATOR);
    }

    /**
     * Whether this disk's URLs are fetchable without the application — the
     * deployment's claim for a configured disk, the brand's for its own.
     */
    public function isPublicDirect(string $diskName): bool
    {
        return ($this->configFor($diskName)['public_direct'] ?? false) === true;
    }

    /**
     * Whether the name resolves at all. A false answer means files recorded
     * on it cannot be read — the tail of an abandoned migration, or a brand
     * whose storage settings were deleted.
     */
    public function exists(string $diskName): bool
    {
        return $this->configFor($diskName) !== null;
    }

    /**
     * Override how one disk name resolves, for this request only.
     *
     * Deliberately here rather than through `Storage::set()`: that registers
     * the disk in the FilesystemManager singleton, where under Octane it
     * would outlive the request and be handed to the next brand. This memo is
     * request-scoped, so an override cannot escape.
     *
     * Used by tests to stand a fake in for a brand's bucket, and available to
     * anything that legitimately needs to redirect resolution within a
     * request.
     */
    public function use(string $diskName, Filesystem $filesystem): void
    {
        $this->memo[$diskName] = $filesystem;
    }

    public function filesystem(string $diskName): Filesystem
    {
        if (isset($this->memo[$diskName])) {
            return $this->memo[$diskName];
        }

        if (! $this->isBrandDisk($diskName)) {
            return $this->memo[$diskName] = Storage::disk($diskName);
        }

        $config = $this->configFor($diskName);

        if ($config === null) {
            throw new RuntimeException("No storage settings exist for disk [{$diskName}].");
        }

        // Built, not registered: a named disk would be cached in the
        // FilesystemManager singleton and survive into the next request.
        return $this->memo[$diskName] = Storage::build($config);
    }

    /**
     * The disk's configuration, whichever kind of name it is, or null when
     * the name resolves to nothing.
     *
     * @return array<string, mixed>|null
     */
    public function configFor(string $diskName): ?array
    {
        if (array_key_exists($diskName, $this->configMemo)) {
            return $this->configMemo[$diskName];
        }

        if (! $this->isBrandDisk($diskName)) {
            /** @var array<string, mixed>|null $config */
            $config = config("filesystems.disks.{$diskName}");

            return $this->configMemo[$diskName] = $config;
        }

        [, $brandId] = explode(self::BRAND_SEPARATOR, $diskName, 2);

        $settings = $this->brandSettings($brandId);

        return $this->configMemo[$diskName] = $settings === null
            ? null
            : [
                'driver' => 's3',
                'key' => $settings->credentials['key'] ?? '',
                'secret' => $settings->credentials['secret'] ?? '',
                'region' => $settings->region,
                'bucket' => $settings->bucket,
                'endpoint' => $settings->endpoint,
                'use_path_style_endpoint' => $settings->use_path_style_endpoint,
                'root' => $settings->prefix,
                'url' => $settings->url,
                'public_direct' => $settings->public_direct,
                'throw' => true,
                'report' => false,
            ];
    }

    /**
     * Cross-brand by ULID, like everything else that resolves storage: the
     * caller holds the brand id, and rendering happens wherever it happens —
     * queued jobs and the brand-less admin host included (ADR-0029).
     */
    private function brandSettings(string $brandId): ?BrandStorageSetting
    {
        return BrandStorageSetting::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->first();
    }
}
