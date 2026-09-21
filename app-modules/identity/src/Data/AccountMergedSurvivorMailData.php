<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.account-merged-survivor` (ADR-0009, #81): the
 * receipt sent to the account an operator-performed merge kept, naming the
 * address it absorbed. A separate template from
 * `AccountMergedClosedMailData`'s rather than one branching on a "kind" flag
 * — `.ai/rules/app-modules-src.md` prefers two keys over one `@if`/`@else`
 * template, the same call `commerce.invoice-issued`/`commerce.invoice-paid`
 * already made.
 */
#[TypeScript]
final class AccountMergedSurvivorMailData extends Data
{
    public function __construct(
        public readonly string $otherEmail,
    ) {}
}
