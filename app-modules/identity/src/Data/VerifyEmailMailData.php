<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.verify-email` (ADR-0009, ADR-0048 point 5).
 * `verifyUrl` is the exact signed URL Laravel's own `VerifyEmail::
 * verificationUrl()` builds (`Mail\AuthMailer::sendVerification()`) — a
 * temporary signature Fortify's `verification.verify` route validates when
 * followed, unrelated to `SendMessage`'s own delivery guarantees.
 */
#[TypeScript]
final class VerifyEmailMailData extends Data
{
    public function __construct(
        public readonly string $verifyUrl,
        public readonly int $validMinutes,
    ) {}
}
