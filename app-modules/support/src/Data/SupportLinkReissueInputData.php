<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The whole of the self-service re-issue form (design §8.2): a reference,
 * and deliberately nothing else.
 *
 * No address field, and that is the security property rather than an
 * omission. A fresh link is always mailed to the address **stored on the
 * row**; a form that accepted one would let anybody holding a reference
 * redirect somebody else's report to an inbox of their choosing, which is
 * the whole attack this endpoint could otherwise be.
 *
 * The reference is the last eight characters of the ULID as the receipt
 * mail and the toast print it, so it is the one string the person
 * demonstrably has. Case is normalised by the Action, because nobody
 * retypes `A1B2C3D4` the way it was printed.
 */
#[TypeScript]
final class SupportLinkReissueInputData extends Data
{
    public function __construct(
        #[Required, StringType, Min(4), Max(64)]
        public readonly string $reference,
    ) {}
}
