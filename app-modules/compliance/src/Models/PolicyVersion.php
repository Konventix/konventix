<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\PolicyVersionFactory;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One version of a Policy: its effective date, published markdown text,
 * whether agreeing to it requires an explicit Consent record, and its
 * publication state (SPEC "Owns").
 *
 * Publication only ever happens through PublishPolicyVersion, never a direct
 * save — that Action is what guarantees at most one Published version is
 * ever effective per policy.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $policy_id
 * @property string $version
 * @property string $content
 * @property bool $requires_consent
 * @property PolicyVersionState $state
 * @property Carbon|null $effective_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PolicyVersion extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<PolicyVersionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'version',
        'content',
        'requires_consent',
        'state',
        'effective_at',
        'published_at',
    ];

    /**
     * @return BelongsTo<Policy, $this>
     */
    public function policy(): BelongsTo
    {
        return $this->belongsTo(Policy::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'requires_consent' => 'boolean',
            'state' => PolicyVersionState::class,
            'effective_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): PolicyVersionFactory
    {
        return PolicyVersionFactory::new();
    }
}
