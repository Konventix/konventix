<?php

declare(strict_types=1);

namespace Konventix\Identity\Social;

use Spatie\LaravelData\Data;

/**
 * A verified identity as a provider asserts it.
 *
 * Deliberately not a `#[TypeScript]` Data object in `src/Data/`: this never
 * reaches the frontend. It is the internal hand-off between a driver and the
 * flow that decides what the identity means, and the values in it are the
 * provider's claims — not a form payload (ADR-0009 governs the latter).
 *
 * An instance existing at all is the assertion that the handshake succeeded;
 * a driver that could not verify throws instead of constructing one.
 */
final class SocialIdentityData extends Data
{
    public function __construct(
        public readonly string $provider,
        /** The provider's own id for this person — a SteamID64 for Steam. */
        public readonly string $providerUserId,
        /** Null when the driver has no profile access; onboarding asks instead. */
        public readonly ?string $nickname = null,
        public readonly ?string $avatarUrl = null,
        /** Always null for Steam — OpenID 2.0 asserts no address. */
        public readonly ?string $email = null,
    ) {}
}
