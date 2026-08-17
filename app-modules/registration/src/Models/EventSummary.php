<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Registration knows it (ADR-0004: this module may not read
 * Event's models, so it projects what it needs from Event's contract events).
 *
 * Exists so an organizer creating a registration type picks an event by name
 * instead of pasting a ULID. Name, slug, and whether it is archived — nothing
 * else, because nothing else here would be used.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated` rather than inventing an event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'registration_event_summaries';

    protected $fillable = ['event_id', 'name', 'slug', 'archived_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'archived_at' => 'datetime',
        ];
    }
}
