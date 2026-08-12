<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

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
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class AdminFavorite extends Model
{
    /** @use HasFactory<AdminFavoriteFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['user_id', 'label', 'url'];

    protected static function newFactory(): AdminFavoriteFactory
    {
        return AdminFavoriteFactory::new();
    }
}
