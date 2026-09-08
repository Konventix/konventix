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

    /*
    |--------------------------------------------------------------------------
    | Expiry Sweeps
    |--------------------------------------------------------------------------
    |
    | #231: before `commerce:expire-carts` or
    | `commerce:expire-registration-orders` cancels an order whose hosted
    | payment is still open, it asks the PSP whether that session was paid
    | after all. Two limits keep that question from becoming its own outage.
    |
    | `probe_budget` caps the provider calls one sweep run may make. Beyond
    | it the run stops asking and simply leaves those orders for the next
    | run — a fifteen-minute delay in releasing a seat, instead of a
    | scheduled command making hundreds of sequential HTTP calls while a
    | provider is slow.
    |
    | `unknown_payment_grace_hours` is the release valve. An order whose
    | provider has never been able to answer would otherwise hold its seat,
    | its quota and its stock for as long as the outage lasts, so past this
    | age it is cancelled anyway. That is safe because the money, if it did
    | move, is found by `commerce:reconcile-payments` and recorded as
    | unapplied money (ADR-0057 point 9), which the console shows and can
    | refund (#6).
    |
    */

    'sweeps' => [
        'probe_budget' => (int) env('COMMERCE_SWEEP_PROBE_BUDGET', 50),
        'unknown_payment_grace_hours' => (int) env('COMMERCE_UNKNOWN_PAYMENT_GRACE_HOURS', 24),
    ],

];
