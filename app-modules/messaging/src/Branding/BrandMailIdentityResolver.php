<?php

declare(strict_types=1);

namespace Konventix\Messaging\Branding;

use Illuminate\Support\Facades\Log;
use Konventix\Messaging\Data\BrandMailIdentityData;
use Konventix\Platform\Branding\BrandIdentityResolver;
use Konventix\Platform\Footer\FooterLinks;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorSettings;
use Konventix\Platform\Theming\TokenCatalog;
use RuntimeException;

/**
 * The brand identity the branded mail layout renders (ADR-0048 point 4):
 * logo and accent colour from Platform's own identity/theme services, the
 * operator's legal line, and the brand's imprint/privacy footer links.
 *
 * Resolved fresh per send from the envelope's own `brandId` — never a
 * singleton holding a brand. A worker sends mail for many brands across its
 * lifetime (Octane); this class is bound `scoped` so its dependencies'
 * per-request memoisation (`BrandIdentityResolver`'s own) dies with the
 * request/job rather than pinning the first brand it resolved.
 *
 * `Brand` is the one Platform model Messaging may import directly (the
 * cross-module allow-list, ArchitectureTest). The logo, seller line and
 * footer links come from Platform's non-model read services instead of
 * `MediaItem`, `Operator` or `FooterLink` — none of those cross the
 * boundary here.
 */
final class BrandMailIdentityResolver
{
    public function __construct(
        private readonly BrandIdentityResolver $identity,
        private readonly OperatorSettings $operatorSettings,
        private readonly FooterLinks $footerLinks,
    ) {}

    public function for(string $brandId): BrandMailIdentityData
    {
        $brand = Brand::query()->findOrFail($brandId);

        $identity = $this->identity->forBrand($brand);
        $tokens = $brand->themeTokens();
        $legal = $this->footerLinks->legalLinksFor($brandId);

        return new BrandMailIdentityData(
            brandId: $brandId,
            brandName: $identity->name,
            logoUrl: $identity->logoUrl,
            logoAlt: $identity->logoAlt ?? $identity->name,
            accentColor: $tokens['accent'] ?? TokenCatalog::lightDefaults()['accent'],
            accentTextColor: $tokens['on-accent'] ?? TokenCatalog::lightDefaults()['on-accent'],
            sellerLine: $this->sellerLine($brandId, $identity->name),
            imprintUrl: $legal['imprintUrl'],
            privacyUrl: $legal['privacyUrl'],
        );
    }

    /**
     * `legalName · addressLine, postalCode city · USt-IdNr. vatId`, each
     * part omitted when absent. A brand with no operator (seed data, a test
     * fixture) degrades to the brand name rather than failing a send over a
     * missing legal identity — `OperatorSettings::sellerForBrand()` throws
     * exactly for that case.
     */
    private function sellerLine(string $brandId, string $brandName): string
    {
        try {
            $seller = $this->operatorSettings->sellerForBrand($brandId);
        } catch (RuntimeException $exception) {
            Log::info('Mail identity: brand has no operator, using the brand name as the seller line.', [
                'brand_id' => $brandId,
                'reason' => $exception->getMessage(),
            ]);

            return $brandName;
        }

        $cityLine = trim(implode(' ', array_filter([$seller->postalCode, $seller->city])));
        $addressSegment = implode(', ', array_filter([$seller->addressLine, $cityLine === '' ? null : $cityLine]));

        $parts = [$seller->legalName];

        if ($addressSegment !== '') {
            $parts[] = $addressSegment;
        }

        if ($seller->vatId !== null) {
            $parts[] = 'USt-IdNr. '.$seller->vatId;
        }

        return implode(' · ', $parts);
    }
}
