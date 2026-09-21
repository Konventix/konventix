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
 * What a reporter posts back into their own thread — `POST
 * /my/support-requests/{request}/reply` and its signed guest twin
 * (design §8).
 *
 * One field, and the absence of the second one is the design: the console's
 * {@see SupportMessageInputData} requires an explicit `visibility` because
 * a staff member can write either kind, while a reporter can only ever
 * write the kind their counterpart reads. Accepting a `visibility` here —
 * even one the controller overwrote — would put the word "internal" on a
 * public endpoint, and the one mistake this table can make is an internal
 * note reaching the wrong side of it (§4.2).
 *
 * The 1–4000 bound is the message column's, the same one the console's
 * reply obeys; there is no lower bound worth enforcing, because "yes,
 * that fixed it" is a legitimate answer.
 */
#[TypeScript]
final class SupportReplyInputData extends Data
{
    public function __construct(
        #[Required, StringType, Min(1), Max(4000)]
        public readonly string $body,
    ) {}
}
