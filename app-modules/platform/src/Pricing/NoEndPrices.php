<?php

declare(strict_types=1);

namespace Konventix\Platform\Pricing;

/**
 * The default binding: no end price for anything (#234).
 *
 * Commerce is a Core module and is normally present, but Platform boots
 * before it and must not depend on that ordering — and a public page that
 * threw because nothing was bound would be a worse bug than one that
 * prints the declared net amount with its "excl. VAT" label, which is
 * exactly what every one of these surfaces did before #234.
 *
 * `NoPublicLegalTexts`' shape, for `NoPublicLegalTexts`' reason.
 */
final class NoEndPrices implements EndPriceCalculator
{
    /**
     * @param  list<array{unitAmountNet: string, taxClass: string}>  $lines
     * @return list<null>
     */
    public function endPricesFor(string $brandId, array $lines): array
    {
        return array_fill(0, count($lines), null);
    }
}
