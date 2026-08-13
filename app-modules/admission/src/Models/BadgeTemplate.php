<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Admission\Data\BadgeLayoutData;
use Konventix\Admission\Database\Factories\BadgeTemplateFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A per-event badge design (D-ADM-05). Admission owns the layout; the assets
 * it names live in Platform's media library and are referenced by ULID only
 * (ADR-0035).
 *
 * `role` doubles as the match key: a template carrying a role serves
 * admissions of that role, and the single `role: null` row is the event's
 * fallback. `forAdmission()` is the one place that resolution happens, so
 * "which badge does this person get" has exactly one answer everywhere.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property string|null $role
 * @property BadgeLayoutData $layout
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BadgeTemplate extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<BadgeTemplateFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'name',
        'role',
        'layout',
    ];

    /**
     * The template that applies to an admission: the one matching its role,
     * else the event's fallback, else none.
     *
     * `withoutBrandScope()` scoped to the admission's own brand (ADR-0010):
     * badge rendering runs from queue workers and the operator host, neither
     * of which has an ambient brand.
     */
    public static function forAdmission(Admission $admission): ?self
    {
        return self::withoutBrandScope()
            ->where('brand_id', $admission->brand_id)
            ->where('event_id', $admission->event_id)
            ->where(fn ($query) => $query->where('role', $admission->role)->orWhereNull('role'))
            // Role-specific beats fallback: NULLs sort last here, so the
            // matching role wins when both exist.
            ->orderByRaw('role IS NULL')
            ->first();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'layout' => BadgeLayoutData::class,
        ];
    }

    protected static function newFactory(): BadgeTemplateFactory
    {
        return BadgeTemplateFactory::new();
    }
}
