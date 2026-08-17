<?php

declare(strict_types=1);

namespace Konventix\Content\Data;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * The comment form (ADR-0009). One field, and the length bounds are the only
 * validation there is — everything else about whether this comment may exist
 * (entry live, comments enabled, account present) belongs to `PostComment`,
 * so a comment arriving by any other route obeys the same rules.
 */
#[TypeScript]
final class PostCommentData extends Data
{
    public function __construct(
        #[Required, StringType, Min(2), Max(5000)]
        public readonly string $body,
    ) {}
}
