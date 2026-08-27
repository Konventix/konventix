<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use Konventix\Commerce\WaitingRoom\RedisWaitingRoomStore;

/**
 * `RedisWaitingRoomStore` against a real Redis connection (ADR-0047 point
 * 5). The rest of the suite drives `WaitingRoomStore` through
 * `ArrayWaitingRoomStore` only (`WAITING_ROOM_STORE=array`,
 * `phpunit.xml`), so nothing else guards phpredis' actual return shapes —
 * see `RedisWaitingRoomStore::admitNext()`'s docblock. This test skips
 * itself when Redis is unreachable rather than failing the whole suite on
 * an infrastructure gap, but it IS reachable in the dev container, so it
 * must actually run there, not skip.
 *
 * Helper names prefixed `redisWaitingRoom*` — Pest helpers are
 * process-global (.ai/rules/tests.md).
 */
function redisWaitingRoomReachable(): bool
{
    try {
        Redis::connection(config('commerce.waiting_room.connection'))->ping();

        return true;
    } catch (Throwable) {
        return false;
    }
}

/**
 * `del()` (and every other read/write command) auto-prepends the client's
 * configured key prefix, but `keys()` returns names that already carry
 * it — round-tripping a `keys()` result straight into `del()` therefore
 * double-prefixes and deletes nothing. Naming every key explicitly here,
 * unprefixed, the same way `RedisWaitingRoomStore`'s own private key
 * builders do, sidesteps that trap entirely.
 *
 * @param  list<string>  $keys
 */
function redisWaitingRoomCleanup(array $keys): void
{
    Redis::connection(config('commerce.waiting_room.connection'))->del(...$keys);
}

it('drives the real Redis store through arrival order, admission, and cleanup', function (): void {
    if (! redisWaitingRoomReachable()) {
        $this->markTestSkipped('waiting-room Redis unreachable');
    }

    $brandId = 'test-'.Str::ulid();
    $store = new RedisWaitingRoomStore;

    $first = (string) Str::ulid();
    $second = (string) Str::ulid();
    $third = (string) Str::ulid();
    $userId = 'user-1';
    $ipHash = hash('sha256', '203.0.113.1');

    $keys = [
        "waiting-room:{$brandId}:queue",
        "waiting-room:{$brandId}:ticket:{$first}",
        "waiting-room:{$brandId}:ticket:{$second}",
        "waiting-room:{$brandId}:ticket:{$third}",
        "waiting-room:{$brandId}:user:{$userId}",
        "waiting-room:{$brandId}:ip:{$ipHash}",
    ];

    try {
        $store->enqueue($brandId, $first, 'https://example.test/shop', null);
        $store->enqueue($brandId, $second, 'https://example.test/cart', $userId);
        $store->enqueue($brandId, $third, 'https://example.test/shop/mug', null);

        expect($store->positionOf($brandId, $first))->toBe(1)
            ->and($store->positionOf($brandId, $second))->toBe(2)
            ->and($store->positionOf($brandId, $third))->toBe(3)
            ->and($store->depth($brandId))->toBe(3)
            ->and($store->ticketForUser($brandId, $userId))->toBe($second);

        // ZPOPMIN pops the head in arrival order and clears the popped
        // tickets' queue position — the third ticket moves up to 1.
        $admitted = $store->admitNext($brandId, 2);
        expect($admitted)->toBe([$first, $second])
            ->and($store->positionOf($brandId, $first))->toBeNull()
            ->and($store->positionOf($brandId, $second))->toBeNull()
            ->and($store->positionOf($brandId, $third))->toBe(1);

        $admittedAt = CarbonImmutable::now();
        $expiresAt = $admittedAt->addMinutes(30);
        $store->markAdmitted($brandId, $first, $admittedAt, $expiresAt);

        expect($store->isAdmitted($brandId, $first))->toBeTrue();

        $until = $store->admittedUntil($brandId, $first);
        expect($until)->toBeInstanceOf(CarbonImmutable::class)
            ->and($until->diffInSeconds($expiresAt, true))->toBeLessThan(2);

        expect($store->intendedUrl($brandId, $first))->toBe('https://example.test/shop');

        expect($store->incrementIpAttempts($brandId, $ipHash))->toBe(1)
            ->and($store->incrementIpAttempts($brandId, $ipHash))->toBe(2);

        $store->forget($brandId, $first);
        expect($store->intendedUrl($brandId, $first))->toBeNull();
    } finally {
        redisWaitingRoomCleanup($keys);
    }
});
