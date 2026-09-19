<?php

declare(strict_types=1);

namespace Konventix\Admission\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * A live claim link as the ticket manager shows it (#292 §4.4): where it
 * went, until when, and the signed URL — rebuilt by
 * `IssueAdmissionClaimLink::urlFor()` on every render, never stored, so it
 * can be copied again without a round trip.
 */
#[TypeScript]
final class InviteStateData extends Data
{
    public function __construct(
        public readonly ?string $intendedEmail,
        public readonly string $expiresAt,
        public readonly string $url,
    ) {}
}
