<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Sessions
    |--------------------------------------------------------------------------
    |
    | D-IDP-17: the per-user session index `Sessions\IndexedRedisSessionHandler`
    | keeps beside the session payloads, so the Security page can list a
    | person's sessions on the redis driver and not only on the database one.
    |
    | Deliberately no env variable and no chart value: both defaults are
    | correct on every deployment, and a mismatch between two roles writing
    | the same index (web, Horizon, scheduler) would split a person's list in
    | half. The test suite overrides `index_prefix` in-process so a real-redis
    | test cannot touch the keys a running instance sharing that Redis reads.
    |
    | `connection` falls back to `session.connection`, which is where
    | `SessionManager::createRedisDriver()` puts the payloads themselves — an
    | unset `SESSION_CONNECTION` therefore means the *default* connection for
    | both halves, so a flush of that database drops index and payload
    | together rather than leaving a list of sessions that no longer exist.
    |
    | The index relies on `maxmemory-policy=noeviction` (the deployment's
    | Redis setting): an evicted meta hash is a working session that silently
    | disappears from its owner's list.
    |
    */

    'sessions' => [
        'index_prefix' => 'sessions:',
        'connection' => null,
    ],

];
