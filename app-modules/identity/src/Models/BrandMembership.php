<?php

declare(strict_types=1);

namespace Konventix\Identity\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Identity\Database\Factories\BrandMembershipFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A user's relationship to one brand (ADR-0016): when they were first seen
 * there and their per-brand preferences. The account itself is global; roles
 * are spatie rows with `teams` bound to `brand_id` (ADR-0013), not columns
 * here.
 *
 * @property string $id
 * @property string $user_id
 * @property string $brand_id
 * @property Carbon $first_seen_at
 * @property array<string, mixed>|null $preferences
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class BrandMembership extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<BrandMembershipFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'first_seen_at', 'preferences'];

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_seen_at' => 'datetime',
            'preferences' => 'array',
        ];
    }

    protected static function newFactory(): BrandMembershipFactory
    {
        return BrandMembershipFactory::new();
    }
}
