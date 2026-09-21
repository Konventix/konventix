<?php

declare(strict_types=1);

namespace Konventix\Messaging\Channels;

/**
 * An address that can never be delivered to, by construction rather than by
 * bounce. RFC 2606 reserves `.invalid` for names guaranteed not to resolve;
 * an account that carries such an address as a stand-in (Identity's
 * placeholder for a Steam-only account, #293) must never see a mail
 * attempt — not because the transport would fail, but because the mail
 * would be a claim to have reached someone nobody can reach. Messaging
 * refuses the whole TLD, so it needs to know nothing about who mints
 * placeholders or why.
 */
final class UndeliverableAddress
{
    public static function is(string $email): bool
    {
        $domain = mb_strtolower((string) strrchr($email, '@'));

        return $domain === '@invalid' || str_ends_with($domain, '.invalid');
    }
}
