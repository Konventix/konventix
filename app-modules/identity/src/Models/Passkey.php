<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Konventix\Identity\Database\Factories\PasskeyFactory;
use Laravel\Passkeys\Passkey as BasePasskey;

/**
 * One WebAuthn credential that signs a person into their account (bugfix
 * round 2026-09-21, Task 16). The package's model with a ULID key
 * (ADR-0014); `Passkeys::usePasskeyModel()` in `IdentityServiceProvider`
 * points the package's actions, route binding and `User::passkeys()` here.
 *
 * Like a social identity, a passkey is a *way into* the account and so a row
 * referencing the user, never a column on User (SPEC.md "Owns").
 *
 * A passkey is bound by the authenticator to the relying-party id it was
 * created under — the request host, see `BindPasskeyRelyingParty` — so a
 * credential made on one brand's domain does not sign in on another's. That
 * fact is not stored here: the browser never offers the credential elsewhere,
 * and the server-side assertion check rejects it if one did.
 *
 * @property string $id
 * @property string $user_id
 */
class Passkey extends BasePasskey
{
    /** @use HasFactory<PasskeyFactory> */
    use HasFactory, HasUlids;

    /**
     * Resolve the factory explicitly: Laravel's convention-based lookup only
     * works for models living under the `App\Models` namespace.
     */
    protected static function newFactory(): PasskeyFactory
    {
        return PasskeyFactory::new();
    }
}
