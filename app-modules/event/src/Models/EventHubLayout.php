<?php

declare(strict_types=1);

namespace Konventix\Event\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The operator's composition of the live hub for one event (design §6):
 * the cards and links in display order, each on or off, plus the "home
 * page becomes the hub" switch. Written only through
 * `Actions\SaveEventHubLayout`, which emits `EventHubLayoutChanged` with the
 * whole layout so Platform can keep its own projection (ADR-0004) —
 * Platform never reads this table.
 *
 * `cards` holds `list<array{key: string, visible: bool}>` exactly as the
 * operator saved it. The keys name entries of Platform's `HubRegistry`;
 * reconciling them with what is registered today (unknown keys, cards a
 * newly installed module added) is Platform's job at render time, so this
 * model stores what it was given and interprets nothing.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property list<array{key: string, visible: bool}> $cards
 * @property bool $hub_replaces_home
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EventHubLayout extends Model
{
    use BelongsToBrand, HasUlids;

    protected $fillable = ['event_id', 'cards', 'hub_replaces_home'];

    /**
     * @return BelongsTo<Event, $this>
     */
    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cards' => 'array',
            'hub_replaces_home' => 'boolean',
        ];
    }
}
