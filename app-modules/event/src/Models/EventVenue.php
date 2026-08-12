<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * The event↔venue assignment pivot (D-EVT-15). Exists as a class solely so
 * the pivot row gets a ULID primary key on attach (ADR-0014 — plain
 * `attach()` on an implicit pivot writes no id at all).
 */
class EventVenue extends Pivot
{
    use HasUlids;

    protected $table = 'event_venues';

    public $incrementing = false;
}
