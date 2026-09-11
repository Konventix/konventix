<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Data\BackgroundData;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Database\Factories\PlanFactory;
use Konventix\Resources\Models\Resource as BookableResource;

/**
 * A hall plan (ADR-0060 point 4, design §4.4): one document, projected into
 * `resources` on every save by `SavePlan`. Geometry lives only in the
 * document; booking logic reads only the projection.
 *
 * `document` is a `PlanDocumentData` behind laravel-data's Eloquent cast
 * (ADR-0009). It defaults to an empty document rather than null because a
 * plan with nothing drawn yet is a legitimate empty list, not an absent one
 * — the same reasoning as `RegistrationType::$grants`.
 *
 * `version` is the optimistic lock: `SavePlan` compares it with the
 * `expectedVersion` the editor sends and increments it inside the save
 * transaction. `published_at` is plan-level publishing; the places'
 * `is_published` is derived from it on every save.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string|null $venue_id
 * @property string $name
 * @property string|null $floor_label
 * @property int $width_mm
 * @property int $height_mm
 * @property BackgroundData|null $background
 * @property string $unit_display
 * @property int $snap_step
 * @property int $version
 * @property PlanDocumentData $document
 * @property Carbon|null $published_at
 * @property string|null $origin_id
 * @property string|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plan extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PlanFactory> */
    use HasFactory, HasUlids;

    protected $table = 'resource_plans';

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'document' => '{"schema":"konventix.plan/v1","elements":[]}',
        'unit_display' => 'cm',
        'snap_step' => 100,
        'version' => 0,
    ];

    protected $fillable = [
        'event_id',
        'venue_id',
        'name',
        'floor_label',
        'width_mm',
        'height_mm',
        'background',
        'unit_display',
        'snap_step',
        'version',
        'document',
        'published_at',
        'origin_id',
        'created_by',
    ];

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    /**
     * @return HasMany<PlanVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PlanVersion::class, 'plan_id');
    }

    /**
     * The projection: one `resources` row per place element, id for id.
     * Soft-deleted places are hidden by the trait; `withTrashed()` on the
     * relation is the opt-in for history.
     *
     * @return HasMany<BookableResource, $this>
     */
    public function places(): HasMany
    {
        return $this->hasMany(Resource::class, 'plan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'width_mm' => 'integer',
            'height_mm' => 'integer',
            'snap_step' => 'integer',
            'version' => 'integer',
            // Nullable value object: a null column is a null property.
            'background' => BackgroundData::class,
            'document' => PlanDocumentData::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PlanFactory
    {
        return PlanFactory::new();
    }
}
