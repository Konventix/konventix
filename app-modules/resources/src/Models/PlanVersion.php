<?php

declare(strict_types=1);

namespace Konventix\Resources\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Resources\Data\PlanDocumentData;
use Konventix\Resources\Database\Factories\PlanVersionFactory;
use Konventix\Resources\Enums\PlanVersionSource;

/**
 * One saved state of a plan (design §4.5), written inside `SavePlan`'s
 * transaction and pruned to the last 50 per plan. Written once, never
 * updated, hence no `updated_at`.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $plan_id
 * @property int $version
 * @property PlanDocumentData $document
 * @property PlanVersionSource $source
 * @property string|null $created_by
 * @property Carbon|null $created_at
 */
class PlanVersion extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PlanVersionFactory> */
    use HasFactory, HasUlids;

    public const null UPDATED_AT = null;

    protected $table = 'resource_plan_versions';

    protected $fillable = ['plan_id', 'version', 'document', 'source', 'created_by'];

    /**
     * @return BelongsTo<Plan, $this>
     */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class, 'plan_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'document' => PlanDocumentData::class,
            'source' => PlanVersionSource::class,
        ];
    }

    protected static function newFactory(): PlanVersionFactory
    {
        return PlanVersionFactory::new();
    }
}
