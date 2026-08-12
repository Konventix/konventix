<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\PolicyFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A named legal document a brand publishes — terms of participation, privacy
 * policy, house rules, photo consent (SPEC "Owns"). The document text itself
 * lives in this policy's PolicyVersion rows; Policy is just the stable
 * slug/name a version publishes under.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $slug
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Policy extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PolicyFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['slug', 'name'];

    /**
     * @return HasMany<PolicyVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(PolicyVersion::class);
    }

    protected static function newFactory(): PolicyFactory
    {
        return PolicyFactory::new();
    }
}
