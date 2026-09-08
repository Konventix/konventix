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
 * Scheme and port come from `APP_URL` (`config('app.url')`), not from the
 * current request. A brand's origin is a property of the deployment — the
 * proxy in front of it terminates TLS on one scheme and one port for every
 * host it serves — and it has to be answerable where no request exists at
 * all. It did ride along from `request()` until #225: a mail rendered inside
 * a queued job (an expired cart line cancelling its registration, say) got
 * the synthetic request Laravel hands back outside HTTP, whose scheme is
 * `http`, and shipped `http://` links for a brand that only answers on
 * `https://`. Reading configuration makes the queue and the web request agree
 * by construction.
 *
 * A developer on a nonstandard port keeps a link that opens by pointing
 * `APP_URL` at that port (`http://localhost:18080`), which is the same string
 * every other absolute URL in the application is already built from.
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
        $appUrl = (string) config('app.url');

        $scheme = parse_url($appUrl, PHP_URL_SCHEME);
        $scheme = is_string($scheme) && $scheme !== '' ? $scheme : 'http';

        // Absent from `APP_URL`, or spelled out as the scheme's own default:
        // either way the origin leaves it unspoken, so a deployment that
        // writes `https://…:443` (or `http://…:80`, which is what mirroring
        // compose's `APP_PORT` default produces) reads as a plain hostname.
        $port = parse_url($appUrl, PHP_URL_PORT);

        $isDefaultPort = ($scheme === 'https' && $port === 443)
            || ($scheme === 'http' && $port === 80);

        return $scheme.'://'.$host.(is_int($port) && ! $isDefaultPort ? ':'.$port : '');
    }
}
