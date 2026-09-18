<?php

declare(strict_types=1);

namespace Konventix\Identity\Profile;

/**
 * The address an account carries while it has none of its own (#293).
 *
 * `users.email` is NOT NULL UNIQUE and the login identifier, so an account
 * that arrives without an address — the Eventula import's Steam-only
 * accounts are the case at hand — still needs a row value. This is the one
 * place that value is minted and recognised; controllers ask
 * `User::hasPlaceholderEmail()` rather than comparing strings.
 *
 * `.invalid` is reserved by RFC 2606 for exactly this: a name guaranteed
 * never to resolve, so nothing that slips past the guards can be delivered.
 * Messaging refuses the whole TLD on its mail channel for the same reason
 * (`SendMessage::refusalFor()`), without knowing this class exists.
 */
final class PlaceholderEmail
{
    public const string DOMAIN = 'pending.invalid';

    public static function forSteam(string $steamId64): string
    {
        return sprintf('steam-%s@%s', $steamId64, self::DOMAIN);
    }

    public static function is(?string $email): bool
    {
        return $email !== null && str_ends_with(mb_strtolower($email), '@'.self::DOMAIN);
    }
}
