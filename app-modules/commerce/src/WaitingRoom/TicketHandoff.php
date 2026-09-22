<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

/**
 * The ticket handed through the redirect the gate answers with (#221).
 *
 * Why this exists: the waiting room's durable carrier is the signed cookie
 * ({@see TicketCookie}), and a client that keeps no cookie — curl, a
 * crawler, a browser configured to refuse them — used to loop `/shop` →
 * `/waiting-room` → `/shop` forever, minting a fresh ticket on every round
 * and spending one of `IssueWaitingRoomTicket::IP_CAP`'s five per hour each
 * time. A single `curl -L https://…/shop` locked an IP out for an hour.
 *
 * The fix carries the ticket the gate just issued through the redirect as a
 * query parameter, so the holding page can answer the very first arrival
 * instead of bouncing it back to `/shop` for a second ticket. The cookie
 * still wins wherever both are present, so an ordinary browser's path is
 * exactly what it was.
 *
 * **This token is deliberately weaker than the cookie, not equal to it.**
 * It is signed with the same `APP_KEY` HMAC — a forged one reads as "no
 * ticket" — but under its own domain prefix, so a value minted for a URL
 * can never be replayed as a cookie or the other way round, and it is read
 * in exactly one place: `WaitingRoomController`, which only ever *shows* a
 * queue position. The gate never accepts it, so the token cannot open the
 * door, cannot mint a ticket and cannot spend the per-IP budget. That is
 * what makes it safe to put in a URL at all: a link copied out of an
 * address bar or a proxy log hands over a view of somebody's place in the
 * queue and nothing else, where a copied cookie would hand over the
 * admission itself.
 *
 * It carries no expiry of its own for the same reason the cookie does not:
 * it stops working the moment the store forgets the ticket, and a shorter
 * life would only push a cookie-less client back through the gate — which
 * is the loop this class exists to close.
 */
final class TicketHandoff
{
    /**
     * The query parameter the gate's redirect carries it in.
     */
    public const QUERY_PARAMETER = 'ticket';

    public static function issue(string $ticketId): string
    {
        return $ticketId.'.'.self::signature($ticketId);
    }

    public static function parse(?string $token): ?string
    {
        if ($token === null || $token === '') {
            return null;
        }

        $parts = explode('.', $token, 2);

        if (count($parts) !== 2 || $parts[0] === '') {
            return null;
        }

        [$ticketId, $signature] = $parts;

        // Constant-time compare, same as TicketCookie::parse() — a
        // comparison whose timing leaks how many leading bytes matched
        // defeats the point of signing.
        return hash_equals(self::signature($ticketId), $signature) ? $ticketId : null;
    }

    /**
     * `handoff:` keeps this HMAC in its own domain: a value minted here is
     * not a valid cookie and a cookie is not a valid handoff, so the weaker
     * of the two credentials can never be promoted into the stronger one.
     */
    private static function signature(string $ticketId): string
    {
        return hash_hmac('sha256', 'handoff:'.$ticketId, (string) config('app.key'));
    }
}
