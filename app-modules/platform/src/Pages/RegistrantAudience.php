<?php

declare(strict_types=1);

namespace Konventix\Platform\Pages;

use Konventix\Platform\Enums\PageVisibility;

/**
 * Who counts as a registrant of a brand, for pages whose visibility is
 * {@see PageVisibility::Registrants}.
 *
 * The same inversion as `WaitingRoom\WaitingRoomGate` (ADR-0047 point 5):
 * Platform owns the contract and binds nothing; Registration binds the one
 * implementation in its own provider, against its own `registrations`
 * table, because what a confirmed registration is belongs to it. Platform
 * resolves the binding lazily through {@see PageAccess} and treats "nothing
 * bound" as "nobody is a registrant" — fail closed.
 *
 * A registrant of a brand is a person holding a confirmed, uncancelled
 * registration for any event of that brand.
 */
interface RegistrantAudience
{
    public function isRegistrantOf(string $brandId, string $userId): bool;
}
