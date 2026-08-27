<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Carbon\CarbonImmutable;

/**
 * The waiting room's queue and ticket book (ADR-0047 point 5), per brand.
 * `RedisWaitingRoomStore` is what every real environment binds to — a
 * sorted set for the queue plus a hash per ticket, atomic `ZPOPMIN`
 * admission — `ArrayWaitingRoomStore` is the same shapes held in PHP
 * arrays, bound only when `WAITING_ROOM_STORE=array` (the test suite,
 * `phpunit.xml`).
 *
 * A ticket id enqueued but never admitted, or admitted and past its
 * `expires_at`, is indistinguishable from one that never existed to every
 * method here except `intendedUrl()`/`ticketForUser()`, which a stale
 * ticket still answers until it is `forget()`-ten or its own TTL lapses —
 * mirrors a browser tab that still has the cookie.
 */
interface WaitingRoomStore
{
    /**
     * Adds a ticket to the tail of the brand's queue, records its intended
     * destination (and, for a signed-in visitor, the user mapping that
     * lets a second visit find the same ticket), and returns its
     * 1-based position.
     */
    public function enqueue(string $brandId, string $ticketId, string $intendedUrl, ?string $userId): int;

    /**
     * The ticket's 1-based position in the queue, or null once it has been
     * admitted (popped) or was never enqueued.
     */
    public function positionOf(string $brandId, string $ticketId): ?int;

    /**
     * Pops up to `$count` ticket ids off the head of the queue, in arrival
     * order — `ZPOPMIN` on the Redis store. Does not itself mark them
     * admitted; {@see markAdmitted()} does that, so the two steps stay
     * separate for the caller to run inside its own lock.
     *
     * @return list<string>
     */
    public function admitNext(string $brandId, int $count): array;

    /**
     * Stamps a popped ticket as admitted until `$expiresAt` — the two
     * timestamps a holding page (Task 13) would show.
     */
    public function markAdmitted(string $brandId, string $ticketId, CarbonImmutable $admittedAt, CarbonImmutable $expiresAt): void;

    /**
     * True while the ticket is admitted AND that admission has not yet
     * expired — a single check for both facts, since an expired admission
     * behaves exactly like never having one.
     */
    public function isAdmitted(string $brandId, string $ticketId): bool;

    /**
     * When the ticket's admission lapses, or null if it was never admitted.
     */
    public function admittedUntil(string $brandId, string $ticketId): ?CarbonImmutable;

    /**
     * How many tickets are currently queued for the brand.
     */
    public function depth(string $brandId): int;

    /**
     * The URL the ticket's holder was trying to reach when it was issued.
     */
    public function intendedUrl(string $brandId, string $ticketId): ?string;

    /**
     * The ticket id already issued to this signed-in user, if the store
     * still remembers one — so a second visit reuses it instead of
     * queueing twice.
     */
    public function ticketForUser(string $brandId, string $userId): ?string;

    /**
     * Removes a ticket entirely — from the queue if still queued, and its
     * own record either way.
     */
    public function forget(string $brandId, string $ticketId): void;

    /**
     * Records one more new-ticket attempt from this IP (hashed — the
     * caller never passes a raw address) and returns the count so far in
     * the current window. The per-IP cap itself is `IssueWaitingRoomTicket`'s
     * call, not this store's; it only counts.
     */
    public function incrementIpAttempts(string $brandId, string $ipHash): int;

    /**
     * Stamps "an admission run happened for this brand, now" (Task 13) —
     * what `AdmitWaitingRoomBatch::handle()` calls on every run it reaches,
     * whether or not anyone was actually admitted, and what
     * `WaitingRoomCheck` reads back to tell "the tick is ticking" from
     * "the tick stopped".
     */
    public function recordAdmissionRun(string $brandId): void;

    /**
     * When the brand's last admission run happened, or null if none ever
     * has (or the record has since expired/been forgotten).
     */
    public function lastAdmissionAt(string $brandId): ?CarbonImmutable;
}
