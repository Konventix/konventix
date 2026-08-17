<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Platform\Database\Factories\AdminFavoriteFactory;

/**
 * A user-pinned operator-console shortcut (ADR-0029), ported concept from
 * LanCore's sidebar favorites (NavFavorites + SidebarFavoriteController).
 *
 * Not brand-owned: the admin console spans brands (ADR-0029), so there is
 * nothing to scope to. `user_id` is a plain ULID — cross-module reference to
 * Identity's User, no FK constraint (ADR-0014).
 *
 * @property string $id
 * @property string $user_id
 * @property string $label
 * @property string $url
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminFavorite extends Model
{
    /** @use HasFactory<AdminFavoriteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'label', 'url', 'position'];

    protected static function newFactory(): AdminFavoriteFactory
    {
        return AdminFavoriteFactory::new();
    }

    /**
     * The user's own order (ADR-0042), label as the tie-breaker for rows that
     * were never reordered or that a stale reorder left behind.
     *
     * @param  Builder<AdminFavorite>  $query
     * @return Builder<AdminFavorite>
     */
    public function scopeInUserOrder(Builder $query): Builder
    {
        return $query->orderBy('position')->orderBy('label');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
        ];
    }
}
