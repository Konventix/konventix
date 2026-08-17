<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\RetentionClockFactory;

/**
 * The moment an event's retention clocks started ticking — recorded from
 * Event's `EventConcluded` (SPEC "Consumes").
 *
 * Compliance keeps the date itself rather than asking Event for it later:
 * that is the read-model discipline (ADR-0004), and it means a retention
 * sweep needs no foreign query at the moment it matters most.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property Carbon $concluded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RetentionClock extends Model
{
    /** @use HasFactory<RetentionClockFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['brand_id', 'event_id', 'concluded_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['concluded_at' => 'datetime'];
    }

    protected static function newFactory(): RetentionClockFactory
    {
        return RetentionClockFactory::new();
    }
}
