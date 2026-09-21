<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * How big the window was (design §5). Untrusted — the browser is the only
 * one who knows — so it is bounded rather than believed: a display problem
 * is usually a width problem, and "1280 x 800 at 2x" is the single most
 * useful line of context a screenshot would otherwise have carried (and
 * screenshots are not collected, D-SUP-08).
 */
#[TypeScript]
final class SupportViewportData extends Data
{
    public function __construct(
        #[IntegerType, Min(0), Max(20000)]
        public readonly int $width,
        #[IntegerType, Min(0), Max(20000)]
        public readonly int $height,
        #[Numeric, Min(0), Max(10)]
        public readonly float $dpr,
    ) {}
}
