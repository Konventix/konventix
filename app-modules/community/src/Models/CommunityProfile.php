<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Community's operator-wide profile read model (ADR-0004): one row per
 * account Community has ever heard of, keyed by the user's own ULID and
 * projected from Identity's events by `Listeners\ProjectCommunityProfile` —
 * idempotent, replayable, and rebuildable from a replay of those events.
 *
 * Deliberately NOT `BelongsToBrand` (accounts are operator-global, ADR-0016)
 * and NOT `HasUlids` (the key comes from the event payload, never generated).
 * No factory: rows exist only through the projection, so tests emit the
 * Identity event instead of inventing a profile (Author-model precedent,
 * `app-modules/content`).
 *
 * @property string $id
 * @property string $username
 * @property string $display_name
 * @property string|null $tagline
 * @property string|null $status_emoji
 * @property array<string, string>|null $social_links
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CommunityProfile extends Model
{
    protected $table = 'community_profiles';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'username', 'display_name', 'tagline', 'status_emoji', 'social_links'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'social_links' => 'array',
        ];
    }
}
