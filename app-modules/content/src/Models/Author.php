<?php

declare(strict_types=1);

namespace Konventix\Content\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Content's author read model (ADR-0004): one row per account Content has
 * ever heard of, keyed by the user's own ULID and projected from Identity's
 * events by `Listeners\ProjectAuthor` — idempotent, replayable, and
 * rebuildable from a replay of those events.
 *
 * Deliberately NOT `BelongsToBrand` (accounts are operator-global, ADR-0016)
 * and NOT `HasUlids` (the key comes from the event payload, never generated).
 * No factory: rows exist only through the projection, so tests emit the
 * Identity event instead of inventing an author.
 *
 * @property string $id
 * @property string $display_name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Author extends Model
{
    protected $table = 'content_authors';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['id', 'display_name'];
}
