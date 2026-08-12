<?php

declare(strict_types=1);

namespace Konventix\Compliance\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Compliance\Database\Factories\ConsentFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A subject's agreement to one PolicyVersion: when, in what context it was
 * captured, and whether it has since been withdrawn (SPEC "Owns").
 *
 * `subject_id` is a plain ULID with no foreign key. Today it always holds a
 * user's ULID — Phase 1 requires an account (decision-log Round 5 D-REG-08)
 * — but the column must not assume a `users` table: guest registration will
 * issue its own non-account subject identity later, and this column has to
 * keep working unchanged when that lands.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $subject_id
 * @property string $policy_version_id
 * @property Carbon $granted_at
 * @property string $capture_context
 * @property Carbon|null $withdrawn_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Consent extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ConsentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'subject_id',
        'policy_version_id',
        'granted_at',
        'capture_context',
        'withdrawn_at',
    ];

    /**
     * @return BelongsTo<PolicyVersion, $this>
     */
    public function policyVersion(): BelongsTo
    {
        return $this->belongsTo(PolicyVersion::class);
    }

    public function isWithdrawn(): bool
    {
        return $this->withdrawn_at !== null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'withdrawn_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ConsentFactory
    {
        return ConsentFactory::new();
    }
}
