<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * Typed payload for `identity.registration-attempt` (ADR-0009, ADR-0016/#18).
 *
 * The notice that carries the *existing* account forward when somebody
 * names its address on the registration form or the Steam signup
 * completion: no validation error is shown to whoever typed it (that would
 * be the disclosure ADR-0016 forbids), so this mail is the only signal the
 * real owner gets, and the only path back in it offers is the ordinary
 * forgot-password form — never a token this class or its caller minted
 * itself, which would make the mail a second, un-throttled password-reset
 * issuer.
 */
#[TypeScript]
final class RegistrationAttemptMailData extends Data
{
    public function __construct(
        public readonly string $resetUrl,
    ) {}
}
