<?php

declare(strict_types=1);

namespace Konventix\Platform\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One of the operator's brands, as an auth page names it (#184, ADR-0054).
 *
 * `url` is the brand's primary domain resolved through `BrandUrl`, and is
 * null for a brand nobody has pointed a domain at yet — a legal state, and
 * one the page renders as plain text rather than a dead link.
 *
 * `current` marks the brand whose host is answering, so the page can say
 * "it also applies on …" without the visitor's own brand in that list.
 * There is deliberately no archive or unlisted flag: `brands` carries no
 * such column, and one belongs here only once a brand needs to stay hidden
 * before its launch (#184).
 */
#[TypeScript]
final class OperatorBrandLinkData extends Data
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly ?string $url,
        public readonly bool $current,
    ) {}
}
