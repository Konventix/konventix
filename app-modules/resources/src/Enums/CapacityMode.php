<?php

declare(strict_types=1);

namespace Konventix\Resources\Enums;

/**
 * How a `ResourceType` counts capacity (ADR-0034).
 *
 * `Unit` is one row per bookable unit — a 40-seat room is 40 resources — and
 * the Postgres exclusion constraint alone makes double-booking impossible.
 *
 * `Quantity` is one row with a `capacity` column — "booth B12, 12 m²" — where
 * the check must *sum* overlapping quantities, which no Postgres constraint
 * can express. ADR-0034 is candid that this makes quantity mode's invariant
 * weaker than unit mode's, and "a direct, acknowledged contradiction of
 * ADR-0020's own stated reason for rejecting application-level checks". The
 * row-level constraint still applies underneath; the summed guard is layered
 * on top inside the booking transaction, under a lock.
 */
enum CapacityMode: string
{
    case Unit = 'unit';
    case Quantity = 'quantity';
}
