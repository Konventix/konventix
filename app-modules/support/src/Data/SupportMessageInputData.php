<?php

declare(strict_types=1);

namespace Konventix\Support\Data;

use Konventix\Support\Enums\MessageVisibility;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Required;
use Spatie\LaravelData\Attributes\Validation\StringType;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * `POST /admin/support-requests/{request}/messages` (design §9.2).
 *
 * `visibility` is required and has no default, which is the one design
 * decision in this class: the difference between a staff note and a
 * sentence the reporter will read is the difference this table can most
 * expensively get wrong (§4.2), so the form has to say which it meant and
 * the console renders the two as visibly different things.
 *
 * The 1–4000 bound matches the report's own upper bound; there is no lower
 * one worth enforcing, because "yes" is a legitimate reply.
 */
#[TypeScript]
final class SupportMessageInputData extends Data
{
    public function __construct(
        #[Required]
        public readonly MessageVisibility $visibility,
        #[Required, StringType, Min(1), Max(4000)]
        public readonly string $body,
    ) {}
}
