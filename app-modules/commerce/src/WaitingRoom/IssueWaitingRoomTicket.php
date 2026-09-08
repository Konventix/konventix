<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Konventix\Platform\Models\Brand;

/**
 * Issues, or re-reads, the visitor's waiting-room ticket (ADR-0047 point
 * 5). Three cases, checked in order — each one that matches skips the
 * rest, so a returning visitor never spends a second slot of the per-IP
 * cap or loses their place in the queue:
 *
 * 1. The request already carries a signed cookie naming a ticket the store
 *    still knows (queued or admitted) — reused as-is. A forged or stale
 *    cookie (wrong signature, or a ticket the store has forgotten) reads
 *    as no cookie at all.
 * 2. No usable cookie, but the visitor is signed in and the store
 *    remembers a ticket for this user that the store still knows (same
 *    check as case 1) — reused, so switching devices or clearing cookies
 *    does not queue the same person twice. The user→ticket mapping
 *    outlives an admission (`RedisWaitingRoomStore::USER_TTL_SECONDS`,
 *    two hours, versus `admission_ttl_minutes`, thirty by default), so
 *    without this check a user whose admission has lapsed but whose
 *    mapping has not would get back a ticket that is neither queued nor
 *    admitted — stuck until the mapping itself expires.
 * 3. Neither — a genuinely new arrival, including a signed-in user whose
 *    remembered ticket the store no longer knows. The per-IP cap (five
 *    new tickets per hour, ADR-0047 point 5's "nothing more" anti-abuse
 *    floor) is charged only here, then a fresh ticket is enqueued —
 *    `enqueue()` overwrites the user mapping too, so case 2 finds the new
 *    ticket next time.
 */
final class IssueWaitingRoomTicket
{
    private const IP_CAP = 5;

    public function __construct(private readonly WaitingRoomStore $store) {}

    public function handle(Brand $brand, Request $request): WaitingRoomTicket
    {
        $brandId = $brand->getKey();

        $rawCookie = $request->cookie(TicketCookie::name($brandId));
        $cookieTicketId = TicketCookie::parse(is_string($rawCookie) ? $rawCookie : null);

        if ($cookieTicketId !== null && $this->isKnown($brandId, $cookieTicketId)) {
            return $this->ticketFor($brandId, $cookieTicketId);
        }

        $userId = $request->user()?->getAuthIdentifier();
        $userId = $userId === null ? null : (string) $userId;

        if ($userId !== null) {
            $existing = $this->store->ticketForUser($brandId, $userId);

            if ($existing !== null && $this->isKnown($brandId, $existing)) {
                return $this->ticketFor($brandId, $existing);
            }
        }

        $ipHash = hash('sha256', $request->ip() ?? '');

        if ($this->store->incrementIpAttempts($brandId, $ipHash) > self::IP_CAP) {
            abort(429);
        }

        $ticketId = (string) Str::ulid();
        $this->store->enqueue($brandId, $ticketId, $request->fullUrl(), $userId);

        return $this->ticketFor($brandId, $ticketId);
    }

    private function isKnown(string $brandId, string $ticketId): bool
    {
        return $this->store->isAdmitted($brandId, $ticketId) || $this->store->positionOf($brandId, $ticketId) !== null;
    }

    private function ticketFor(string $brandId, string $ticketId): WaitingRoomTicket
    {
        return new WaitingRoomTicket(
            id: $ticketId,
            position: $this->store->positionOf($brandId, $ticketId),
            isAdmitted: $this->store->isAdmitted($brandId, $ticketId),
        );
    }
}
