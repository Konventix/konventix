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
    | provider is slow. Only a question that could have cost a call spends
    | budget: a provider that cannot be asked about a payment at all answers
    | locally and for free.
    |
    | **A zero in any of the three falls back to the default here.** A zero
    | budget is not "stop probing",
    | it is "leave every order unasked", and an unasked order reaches no
    | release valve — so a zero would stop the sweeps cancelling anything at
    | all, silently and for ever. A zero grace puts the deadline at the
    | payment window's own end, which is past by construction, so the valve
    | would fire on the first run. A non-numeric value casts to zero through
    | `env()` and lands in the same place, which is why the fallback is in the
    | code and not only in this comment. Probing cannot be switched off from
    | here.
    |
    | `unknown_payment_grace_hours` is the release valve for a provider that
    | cannot be *reached*. An order whose PSP stays silent would otherwise
    | hold its seat, its quota and its stock for as long as the outage lasts,
    | so past this age it is cancelled anyway and an operator is told. That
    | is safe because the money, if it did move, is found by
    | `commerce:reconcile-payments` and recorded as unapplied money
    | (ADR-0057 point 9), which the console shows and can refund (#6).
    |
    | `in_flight_grace_days` is the separate, much longer horizon for a
    | provider that *answers* — with "the money is on its way". SEPA direct
    | debit and bank transfer take banking days, so the hours above would
    | cancel exactly the payments the sweep's PSP question exists to
    | protect. A fortnight is longer than any of those methods takes; past
    | it the order is called off and an operator is told.
    |
    | An order the run never asked about (budget spent) reaches neither
    | valve: not asking is not an answer.
    |
    */

    'sweeps' => [
        'probe_budget' => (int) env('COMMERCE_SWEEP_PROBE_BUDGET', 50),
        'unknown_payment_grace_hours' => (int) env('COMMERCE_UNKNOWN_PAYMENT_GRACE_HOURS', 24),
        'in_flight_grace_days' => (int) env('COMMERCE_IN_FLIGHT_GRACE_DAYS', 14),
    ],

];
