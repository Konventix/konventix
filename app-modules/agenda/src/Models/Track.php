<?php

declare(strict_types=1);

namespace Konventix\Agenda\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Agenda\Database\Factories\TrackFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A column of the published grid — "Bühne", "Turniere", "Workshops"
 * (design §4.1). An entity rather than a free-text field because a grid
 * needs a stable, ordered column set, and `position` is that order.
 *
 * `event_id` is a plain ULID with no FK: Event is another module
 * (ADR-0014). Deleting a track leaves its items untracked rather than
 * deleting a programme entry somebody published.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Track extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<TrackFactory> */
    use HasFactory, HasUlids;

    protected $table = 'agenda_tracks';

    protected $fillable = [
        'event_id',
        'name',
        'position',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }

    protected static function newFactory(): TrackFactory
    {
        return TrackFactory::new();
    }
}
