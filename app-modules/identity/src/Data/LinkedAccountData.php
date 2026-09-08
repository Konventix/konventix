<?php

declare(strict_types=1);

namespace Konventix\Identity\Data;

use Konventix\Identity\Models\SocialIdentity;
use Konventix\Identity\Social\SocialProvider;
use Spatie\LaravelData\Data;
use Spatie\TypeScriptTransformer\Attributes\TypeScript;

/**
 * One provider as the linked-accounts page sees it (ADR-0009: the shape the
 * page reads and its TypeScript type come from here, never from a hand-written
 * interface or an inline `->map()` in the controller).
 *
 * Every registered provider gets a row, linked or not — the page's job is to
 * show the ways into this account that exist *and* the ones that could.
 *
 * `providerUserId` is deliberately absent. The page needs to say "Steam is
 * connected", not publish the SteamID64; the identifier is personal data and
 * belongs in the GDPR export, not in a prop on a settings screen.
 */
#[TypeScript]
final class LinkedAccountData extends Data
{
    public function __construct(
        public readonly string $provider,
        public readonly string $label,
        public readonly bool $linked,
        public readonly ?string $nickname,
        public readonly ?string $avatarUrl,
        public readonly ?string $linkedAt,
        /**
         * Whether unlinking this is allowed at all. False when it is the only
         * way into the account — see `Actions\UnlinkSocialIdentity`.
         */
        public readonly bool $canUnlink,
    ) {}

    public static function ofProvider(
        SocialProvider $provider,
        ?SocialIdentity $identity,
        bool $canUnlink,
    ): self {
        return new self(
            provider: $provider->key(),
            label: $provider->label(),
            linked: $identity !== null,
            nickname: $identity?->nickname,
            avatarUrl: $identity?->avatar_url,
            // ISO 8601 with the offset (#243): the page renders it in the
            // reader's own zone, and an offsetless string would be read as
            // their local clock and shifted a second time.
            linkedAt: $identity?->linked_at->toIso8601String(),
            canUnlink: $identity !== null && $canUnlink,
        );
    }
}
