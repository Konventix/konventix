<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.password-reset` (ADR-0009, ADR-0048 point 5).
 * `resetUrl` carries the plaintext token Fortify's password broker just
 * generated (`Mail\AuthMailer::sendPasswordReset()`) — only its hash is
 * stored, so this is the one place the token exists in the clear.
 */
#[TypeScript]
final class PasswordResetMailData extends Data
{
    public function __construct(
        public readonly string $resetUrl,
        public readonly int $validMinutes,
    ) {}
}
