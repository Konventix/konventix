<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * What an invitee sees before claiming (#292 §4.4): the event, the role,
 * who invited them, when the offer closes, and — for a bound link — the
 * address it is meant for. `alreadyHolderOfAnother` is informational: two
 * tickets for one person is a manager's decision, not the system's.
 * `claimUrl` is the page's own signed URL, which the POST shares: the
 * signature covers path and query, never the method.
 */
#[TypeScript]
final class ClaimLandingData extends Data
{
    public function __construct(
        public readonly string $linkId,
        public readonly ?string $eventName,
        public readonly string $role,
        public readonly ?string $managerName,
        public readonly ?string $intendedEmail,
        public readonly string $expiresAt,
        public readonly bool $alreadyHolderOfAnother,
        public readonly string $claimUrl,
    ) {}
}
