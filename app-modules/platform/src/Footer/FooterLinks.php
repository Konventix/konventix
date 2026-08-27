<?php

declare(strict_types=1);

namespace Konventix\Platform\Footer;

use Illuminate\Support\Collection;
use Konventix\Platform\Brands\BrandUrl;
use Konventix\Platform\Enums\FooterLinkKind;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\FooterLink;

/**
 * The two footer links a brand's legal line most often needs: imprint and
 * privacy policy, picked out of the same rows the page footer renders
 * (`FooterLink::scopeInDisplayOrder()` — operator-wide first, then the
 * brand's own).
 *
 * A plain service, not a model: `FooterLink` is Platform-owned data, and a
 * module other than Platform may not import an Eloquent model across the
 * boundary (ADR-0004) even though every Platform *class* is reachable.
 * Messaging's branded mail layout is the first caller — the same doorway
 * shape as `OperatorSettings` and `Branding\BrandIdentityResolver`.
 *
 * Stateless — safe as a plain container resolution under Octane.
 */
final class FooterLinks
{
    private const string IMPRINT_PATTERN = '/impressum|imprint/i';

    private const string PRIVACY_PATTERN = '/datenschutz|privacy/i';

    public function __construct(private readonly BrandUrl $brandUrl) {}

    /**
     * @return array{imprintUrl: ?string, privacyUrl: ?string}
     */
    public function legalLinksFor(string $brandId): array
    {
        $brand = Brand::query()->find($brandId);

        if ($brand === null || $brand->operator_id === null) {
            return ['imprintUrl' => null, 'privacyUrl' => null];
        }

        $links = FooterLink::query()
            ->where('operator_id', $brand->operator_id)
            ->where(fn ($query) => $query->whereNull('brand_id')->orWhere('brand_id', $brandId))
            ->where('kind', FooterLinkKind::Url)
            ->whereNotNull('url')
            ->inDisplayOrder()
            ->get();

        return [
            'imprintUrl' => $this->absolute($brand, $this->firstMatching($links, self::IMPRINT_PATTERN)),
            'privacyUrl' => $this->absolute($brand, $this->firstMatching($links, self::PRIVACY_PATTERN)),
        ];
    }

    /**
     * @param  Collection<int, FooterLink>  $links
     */
    private function firstMatching(Collection $links, string $pattern): ?FooterLink
    {
        return $links->first(fn (FooterLink $link): bool => preg_match($pattern, $link->label) === 1);
    }

    /**
     * `FooterLinkData`'s own validation only ever allows a root-relative
     * path, an absolute http(s) URL, or a `mailto:` address — only the
     * first needs the brand's own host prefixed onto it. Mail has no
     * request to resolve a relative link against, unlike a page footer.
     */
    private function absolute(Brand $brand, ?FooterLink $link): ?string
    {
        if ($link === null || $link->url === null) {
            return null;
        }

        if (! str_starts_with($link->url, '/')) {
            return $link->url;
        }

        $base = $this->brandUrl->baseFor($brand);

        return $base === null ? $link->url : rtrim($base, '/').$link->url;
    }
}
