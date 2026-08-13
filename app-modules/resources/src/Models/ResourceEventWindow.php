<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * When an event runs, as Resources knows it (ADR-0004: this module may not
 * read Event's models, so it projects what it needs from Event's contract
 * events).
 *
 * Exists only to give whole-event resource claims an interval, which
 * ADR-0020 requires every booking to carry.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ResourceEventWindow extends Model
{
    use BelongsToBrand;
    use HasUlids;

    protected $fillable = ['event_id', 'starts_at', 'ends_at'];

    public static function forEvent(string $brandId, string $eventId): ?self
    {
        return self::withoutBrandScope()
            ->where('brand_id', $brandId)
            ->where('event_id', $eventId)
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }
}
