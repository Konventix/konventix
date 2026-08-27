<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

/**
 * The signed ticket cookie (ADR-0047 point 5). `issue()`/`parse()` are the
 * whole trust boundary: a browser could send any string, so the ticket id
 * travels with an HMAC over Laravel's own `APP_KEY` and `parse()` refuses
 * anything whose signature does not match — a forged or tampered cookie
 * reads as "no ticket" rather than as someone else's place in the queue.
 *
 * One cookie per brand (`waiting_room_{brandId}`), never one shared name:
 * a person browsing two brands on this platform holds two independent
 * tickets, and neither name collides with the other's Pennant state.
 */
final class TicketCookie
{
    /**
     * @return non-empty-string
     */
    public static function name(string $brandId): string
    {
        return "waiting_room_{$brandId}";
    }

    public static function issue(string $ticketId): string
    {
        return $ticketId.'.'.hash_hmac('sha256', $ticketId, (string) config('app.key'));
    }

    public static function parse(?string $cookie): ?string
    {
        if ($cookie === null || $cookie === '') {
            return null;
        }

        $parts = explode('.', $cookie, 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }

        [$ticketId, $signature] = $parts;
        $expected = hash_hmac('sha256', $ticketId, (string) config('app.key'));

        // Constant-time compare — the whole point of signing is defeated
        // by a comparison whose timing leaks how many leading bytes matched.
        return hash_equals($expected, $signature) ? $ticketId : null;
    }
}
