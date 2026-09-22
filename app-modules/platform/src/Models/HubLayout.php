<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The operator's hub layout for one event, as Platform knows it (design
 * §6): a projection of Event's `EventHubLayoutChanged`, written only by
 * `Listeners\ProjectHubLayout`. Event owns the truth in
 * `event_hub_layouts`; this row is what the hub page and the home switch
 * read, through `Hub\HubLayoutResolver`, and it is rebuildable by replay.
 * `event_id` is Event's ULID (ADR-0014), so there is no FK.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property list<array{key: string, visible: bool}> $cards
 * @property bool $hub_replaces_home
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HubLayout extends Model
{
    use BelongsToBrand, HasUlids;

    protected $table = 'platform_hub_layouts';

    protected $fillable = ['event_id', 'cards', 'hub_replaces_home'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cards' => 'array',
            'hub_replaces_home' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
