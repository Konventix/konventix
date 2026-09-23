<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * An event as Competition knows it (design §10, ADR-0004): the two facts the
 * public tournament pages need and Competition may not read from Event's
 * models — the `slug` `/events/{slug}/tournaments` resolves by, and the
 * `name` the pages are headed with.
 *
 * No factory: rows exist only through `Listeners\ProjectEventSummary`, so a
 * test emits `EventCreated` rather than inventing one. An event that
 * predates the projection arrives through `event:replay-details`, which
 * re-emits `EventDetailsUpdated` for every event.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $slug
 * @property string|null $name
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EventSummary extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $table = 'competition_event_summaries';

    protected $fillable = ['event_id', 'slug', 'name'];
}
