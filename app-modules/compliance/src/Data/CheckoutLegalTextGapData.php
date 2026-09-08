<?php

declare(strict_types=1);

namespace Konventix\Compliance\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One brand whose checkout resolves no text for at least one of
 * `CheckoutLegalTexts::SLUGS` (#200).
 *
 * A gap is silent everywhere else: `CheckoutLegalTexts::forBrand()` simply
 * returns fewer entries, the consent control renders nothing, and the
 * checkout goes through without asking anything. The policy console is
 * where an operator can be told, so this is what it is told.
 *
 * `missingSlugs` is the *resolved* answer, not "has no row of its own": a
 * brand that shadows the operator's terms with a policy it never published
 * has a row and no text, which is exactly the case that produced the
 * report in the issue.
 */
#[TypeScript]
final class CheckoutLegalTextGapData extends Data
{
    /**
     * @param  list<string>  $missingSlugs
     */
    public function __construct(
        public readonly string $brandId,
        public readonly string $brandName,
        public readonly array $missingSlugs,
    ) {}
}
