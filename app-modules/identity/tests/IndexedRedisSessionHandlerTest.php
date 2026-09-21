<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Konventix\Identity\Models\User;
use Konventix\Identity\Sessions\CacheSessionIndex;
use Konventix\Identity\Sessions\IndexedRedisSessionHandler;

/**
 * `Sessions\IndexedRedisSessionHandler` itself (D-IDP-17), over the array
 * cache store and a `CacheSessionIndex` so nothing here needs a reachable
 * Redis — the real connection is exercised once in
 * `IndexedRedisSessionHandlerRedisTest`.
 *
 * The point of these cases is the Octane shape: one handler instance serves
 * every request of a worker's lifetime and is handed no new container
 * between them (`GiveNewApplicationInstanceToSessionManager` is an empty
 * method), so anything it captured at construction would be pinned into the
 * worker forever — the failure `.ai/rules/providers.md` describes for
 * boot-time singletons. Two simulated requests through one instance must
 * therefore record their own IP and their own user.
 *
 * Helper prefixed `identitySessionHandler*` — Pest helpers are
 * process-global (`.ai/rules/tests.md`).
 */
function identitySessionHandlerSimulateRequest(string $ip, string $userAgent): void
{
    app()->instance('request', Request::create('/settings/security', 'GET', [], [], [], [
        'REMOTE_ADDR' => $ip,
        'HTTP_USER_AGENT' => $userAgent,
    ]));
}

function identitySessionHandlerMake(CacheSessionIndex $index): IndexedRedisSessionHandler
{
    return new IndexedRedisSessionHandler(Cache::store('array'), (int) config('session.lifetime'), $index);
}

it('records each request\'s own user and address through one long-lived handler', function () {
    $index = app(CacheSessionIndex::class);
    $handler = identitySessionHandlerMake($index);

    $first = User::factory()->create();
    $second = User::factory()->create();

    identitySessionHandlerSimulateRequest('203.0.113.7', 'First Browser');
    Auth::login($first);
    $handler->write('session-one', 'payload-one');

    // The next request of the same worker: a new request instance, a new
    // guard, the same handler object.
    app()->forgetScopedInstances();
    Auth::forgetGuards();
    identitySessionHandlerSimulateRequest('198.51.100.9', 'Second Browser');
    Auth::login($second);
    $handler->write('session-two', 'payload-two');

    expect($index->meta('session-one'))->toMatchArray([
        'user_id' => (string) $first->getKey(),
        'ip_address' => '203.0.113.7',
        'user_agent' => 'First Browser',
    ])
        ->and($index->meta('session-two'))->toMatchArray([
            'user_id' => (string) $second->getKey(),
            'ip_address' => '198.51.100.9',
            'user_agent' => 'Second Browser',
        ])
        ->and($index->sessionIdsFor((string) $first->getKey()))->toBe(['session-one'])
        ->and($index->sessionIdsFor((string) $second->getKey()))->toBe(['session-two'])
        ->and($handler->read('session-one'))->toBe('payload-one')
        ->and($handler->read('session-two'))->toBe('payload-two');
});

it('keeps a guest session out of the index', function () {
    $index = app(CacheSessionIndex::class);
    $handler = identitySessionHandlerMake($index);

    identitySessionHandlerSimulateRequest('203.0.113.7', 'A Browser');
    Auth::forgetGuards();

    $handler->write('guest-session', 'payload');

    expect($handler->read('guest-session'))->toBe('payload')
        ->and($index->meta('guest-session'))->toBeNull();
});

it('learns the owner from the stored meta when the guard is already empty', function () {
    // Fortify's AuthenticatedSessionController::destroy() logs the guard out
    // *before* it invalidates the session, so by the time destroy() runs
    // there is nobody to ask — the hash is the only place the owner survives.
    $index = app(CacheSessionIndex::class);
    $handler = identitySessionHandlerMake($index);

    $user = User::factory()->create();

    identitySessionHandlerSimulateRequest('203.0.113.7', 'A Browser');
    Auth::login($user);
    $handler->write('signed-in-session', 'payload');

    Auth::logout();
    $handler->destroy('signed-in-session');

    expect($index->meta('signed-in-session'))->toBeNull()
        ->and($index->sessionIdsFor((string) $user->getKey()))->toBe([])
        ->and($handler->read('signed-in-session'))->toBe('');
});

it('refreshes the meta and the user set on every write', function () {
    $index = app(CacheSessionIndex::class);
    $handler = identitySessionHandlerMake($index);

    $user = User::factory()->create();

    identitySessionHandlerSimulateRequest('203.0.113.7', 'A Browser');
    Auth::login($user);

    $this->travelTo(now()->subMinutes(30));
    $handler->write('same-session', 'first');
    $stale = $index->meta('same-session')['last_activity'];

    $this->travelBack();
    $handler->write('same-session', 'second');

    expect($index->meta('same-session')['last_activity'])->toBeGreaterThan($stale)
        ->and($index->sessionIdsFor((string) $user->getKey()))->toBe(['same-session']);
});
