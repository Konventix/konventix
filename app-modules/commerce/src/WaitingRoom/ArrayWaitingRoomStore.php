<?php

declare(strict_types=1);

namespace Konventix\Commerce\WaitingRoom;

use Carbon\CarbonImmutable;

/**
 * `WaitingRoomStore` held in PHP arrays instead of Redis — bound only when
 * `WAITING_ROOM_STORE=array` (`phpunit.xml`; `CommerceServiceProvider::
 * registerBindings()`). **Test-only.** Bound as a singleton — safe only
 * because it holds no request-scoped binding of its own (no `BrandContext`,
 * no `Auth`, nothing Octane would need forgotten between requests), just
 * the queue's own state, which is exactly what a shared in-memory store
 * across a test's several HTTP calls needs to behave like Redis would. It
 * must never be bound in a real request-serving environment.
 */
final class ArrayWaitingRoomStore implements WaitingRoomStore
{
    /** @var array<string, array<string, float>> brandId => [ticketId => arrival score] */
    private array $queues = [];

    /** @var array<string, array<string, array{url: string, user: ?string, admittedUntil: ?CarbonImmutable}>> */
    private array $tickets = [];

    /** @var array<string, array<string, string>> brandId => [userId => ticketId] */
    private array $userTickets = [];

    /** @var array<string, array<string, array{count: int, resetAt: int}>> brandId => [ipHash => state] */
    private array $ipAttempts = [];

    public function enqueue(string $brandId, string $ticketId, string $intendedUrl, ?string $userId): int
    {
        $this->queues[$brandId][$ticketId] = microtime(true);
        $this->tickets[$brandId][$ticketId] = [
            'url' => $intendedUrl,
            'user' => $userId,
            'admittedUntil' => null,
        ];

        if ($userId !== null) {
            $this->userTickets[$brandId][$userId] = $ticketId;
        }

        return $this->positionOf($brandId, $ticketId) ?? 1;
    }

    public function positionOf(string $brandId, string $ticketId): ?int
    {
        $queue = $this->queues[$brandId] ?? [];

        if (! array_key_exists($ticketId, $queue)) {
            return null;
        }

        asort($queue);
        $rank = array_search($ticketId, array_keys($queue), true);

        return $rank === false ? null : $rank + 1;
    }

    public function admitNext(string $brandId, int $count): array
    {
        $queue = $this->queues[$brandId] ?? [];
        asort($queue);

        $ids = array_slice(array_keys($queue), 0, max(0, $count));

        foreach ($ids as $id) {
            unset($this->queues[$brandId][$id]);
        }

        return $ids;
    }

    public function markAdmitted(string $brandId, string $ticketId, CarbonImmutable $admittedAt, CarbonImmutable $expiresAt): void
    {
        if (! isset($this->tickets[$brandId][$ticketId])) {
            return;
        }

        $this->tickets[$brandId][$ticketId]['admittedUntil'] = $expiresAt;
    }

    public function isAdmitted(string $brandId, string $ticketId): bool
    {
        $expiresAt = $this->admittedUntil($brandId, $ticketId);

        return $expiresAt !== null && $expiresAt->isFuture();
    }

    public function admittedUntil(string $brandId, string $ticketId): ?CarbonImmutable
    {
        return $this->tickets[$brandId][$ticketId]['admittedUntil'] ?? null;
    }

    public function depth(string $brandId): int
    {
        return count($this->queues[$brandId] ?? []);
    }

    public function intendedUrl(string $brandId, string $ticketId): ?string
    {
        return $this->tickets[$brandId][$ticketId]['url'] ?? null;
    }

    public function ticketForUser(string $brandId, string $userId): ?string
    {
        return $this->userTickets[$brandId][$userId] ?? null;
    }

    public function forget(string $brandId, string $ticketId): void
    {
        unset($this->queues[$brandId][$ticketId], $this->tickets[$brandId][$ticketId]);
    }

    public function incrementIpAttempts(string $brandId, string $ipHash): int
    {
        $now = time();
        $state = $this->ipAttempts[$brandId][$ipHash] ?? null;

        if ($state === null || $state['resetAt'] <= $now) {
            $state = ['count' => 0, 'resetAt' => $now + 3600];
        }

        $state['count']++;
        $this->ipAttempts[$brandId][$ipHash] = $state;

        return $state['count'];
    }
}
