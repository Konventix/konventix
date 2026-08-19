<?php

declare(strict_types=1);

namespace Konventix\Platform\Brands;

use Illuminate\Support\Collection;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;

/**
 * The origin a brand answers on — `https://sxlan.example` — so code running
 * somewhere else can link into it.
 *
 * The operator console runs on the admin host and resolves no brand
 * (ADR-0029), so `route()` there yields admin-host URLs: right for console
 * routes, useless for pointing an editor at the page they just published. A
 * public link has to name the brand's own host, and that mapping lives here
 * rather than being rebuilt by every screen that wants one.
 *
 * Scheme and port ride along from the current request rather than from
 * `APP_URL`: a developer on `http://sxlan.localhost:8000` needs a link that
 * works, and so does production on https.
 *
 * Octane: no request is held. `request()` is resolved per call, the way
 * anything request-derived must be in a long-lived worker.
 */
final class BrandUrl
{
    /**
     * Null when the brand answers on no host at all — a legal state for a
     * brand somebody created but has not pointed a domain at yet.
     */
    public function baseFor(Brand $brand): ?string
    {
        return $this->basesFor(new Collection([$brand]))[$brand->getKey()] ?? null;
    }

    /**
     * One query for a whole page of brands, so a list of entries does not turn
     * into a domain lookup per row.
     *
     * @param  Collection<int, Brand>  $brands
     * @return array<string, string> brand ULID => origin
     */
    public function basesFor(Collection $brands): array
    {
        $ids = $brands->map(fn (Brand $brand): string => (string) $brand->getKey())->all();

        if ($ids === []) {
            return [];
        }

        /** @var Collection<int, BrandDomain> $domains */
        $domains = BrandDomain::query()
            ->whereIn('brand_id', $ids)
            // Primary last, so it overwrites the others in the map below: a
            // brand routinely answers on several hosts (apex, www, legacy) and
            // only one of them is the one to link to.
            ->orderBy('is_primary')
            ->get(['brand_id', 'host']);

        return $domains
            ->mapWithKeys(fn (BrandDomain $domain): array => [
                $domain->brand_id => $this->origin($domain->host),
            ])
            ->all();
    }

    /**
     * The port is omitted when it is the scheme's default, so the common case
     * reads as a plain hostname rather than `https://brand.example:443`.
     */
    private function origin(string $host): string
    {
        $request = request();
        $scheme = $request->getScheme();
        $port = $request->getPort();

        $isDefaultPort = ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$host.($isDefaultPort ? '' : ':'.$port);
    }
}
