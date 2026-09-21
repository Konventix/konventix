<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.account-merged-closed` (ADR-0009, #81): the
 * notice sent to the address an operator-performed merge closed, naming the
 * surviving account and inviting the recipient to contact support if this
 * was not something they asked for. This is the one safety net a merge that
 * skips self-service proof-of-control still gets — see
 * `Actions\MergeUserAccounts`'s docblock.
 */
#[TypeScript]
final class AccountMergedClosedMailData extends Data
{
    public function __construct(
        public readonly string $survivorEmail,
    ) {}
}
