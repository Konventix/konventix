<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Nullable;
use Spatie\LaravelData\Attributes\Validation\Regex;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One entry of the visit ring buffer (design §5, §6.3): a page the reporter
 * was on and the correlation id that page's request carried.
 *
 * The reason the buffer exists at all: people report the page they are
 * looking at, and the thing that broke happened on the page before it. Five
 * entries is what makes "I clicked pay and ended up here" reconstructable
 * from the logs without asking the person anything.
 *
 * Every field is client-supplied and therefore untrusted: the URL is length
 * bounded and the id must look like a correlation id or it is dropped
 * (`SupportContextData::CORRELATION_ID`).
 */
#[TypeScript]
final class SupportVisitData extends Data
{
    public function __construct(
        #[StringType, Max(2048)]
        public readonly string $url,
        #[Nullable, StringType, Regex(SupportContextData::CORRELATION_ID)]
        public readonly ?string $correlationId,
        #[Nullable, StringType, Max(40)]
        public readonly ?string $at,
    ) {}
}
