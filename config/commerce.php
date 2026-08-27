<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Waiting Room
    |--------------------------------------------------------------------------
    |
    | ADR-0047 point 5: the door a rush stands behind. `store` picks which
    | WaitingRoomStore implementation CommerceServiceProvider::
    | registerBindings() binds — `redis` in every real environment,
    | `array` only for the test suite (phpunit.xml sets WAITING_ROOM_STORE
    | to it), where the in-memory ArrayWaitingRoomStore singleton is
    | test-only and must never be bound in production. `connection` names
    | the Redis connection in config/database.php — its own database
    | (REDIS_WAITING_ROOM_DB), separate from cache and sessions, so a
    | flush of one never drops the other.
    |
    */

    'waiting_room' => [
        'store' => env('WAITING_ROOM_STORE', 'redis'),
        'connection' => 'waiting-room',
    ],

];
