<?php

declare(strict_types=1);

namespace Konventix\Platform\Branding;

use Konventix\Platform\Data\BrandIdentityData;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\Operator;

/**
 * Turns whoever the request resolved to into the identity the shell renders.
 *
 * Three media lookups per request would otherwise repeat for every asset on
 * every page, so results are memoized per brand. Deliberately in-instance
 * rather than in the cache store: a media URL depends on the brand's storage
 * settings (ADR-0043 point 6), which a cross-request cache keyed on the
 * brand alone would happily serve stale. The binding is scoped, so the memo
 * dies with the request even on a long-lived Octane worker.
 */
final class BrandIdentityResolver
{
    /** @var array<string, BrandIdentityData> */
    private array $memo = [];

    public function __construct(private readonly MediaLibrary $library) {}

    public function forBrand(Brand $brand): BrandIdentityData
    {
        if (isset($this->memo[$brand->getKey()])) {
            return $this->memo[$brand->getKey()];
        }

        // One call for the light mark, because a renderer needs its URL and
        // its alt text together and MediaLibrary::referenceFor exists to
        // spare the second lookup.
        $logo = $brand->logo_media_id === null
            ? null
            : $this->library->referenceFor($brand->logo_media_id);

        return $this->memo[$brand->getKey()] = new BrandIdentityData(
            name: $brand->name,
            logoUrl: $logo['url'] ?? null,
            logoDarkUrl: $this->urlFor($brand->logo_dark_media_id),
            logoAlt: $logo['altText'] ?? null,
            faviconUrl: $this->urlFor($brand->favicon_media_id),
        );
    }

    /**
     * The operator console's identity (ADR-0029). No assets: media is
     * brand-owned and the admin host resolves no brand, so the console
     * always wears the bundled mark and says whose console it is.
     */
    public function forOperator(Operator $operator): BrandIdentityData
    {
        return new BrandIdentityData(
            name: $operator->name,
            logoUrl: null,
            logoDarkUrl: null,
            logoAlt: null,
            faviconUrl: null,
        );
    }

    /**
     * What the shell wears when nothing is resolved at all — the entrance
     * client, error pages rendered before middleware, tests.
     */
    public function fallback(): BrandIdentityData
    {
        return new BrandIdentityData(
            name: (string) config('app.name', 'Konventix'),
            logoUrl: null,
            logoDarkUrl: null,
            logoAlt: null,
            faviconUrl: null,
        );
    }

    private function urlFor(?string $mediaId): ?string
    {
        return $mediaId === null ? null : $this->library->urlFor($mediaId);
    }
}
