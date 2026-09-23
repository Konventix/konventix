<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\DisciplineFactory;
use Konventix\Competition\Enums\DisciplineKind;
use Konventix\Competition\Enums\LinkedProvider;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * What is being contested (ADR-0024).
 *
 * The ADR's point is that a video game is one *kind* of discipline rather than
 * the only one, so duration, team size and scoring live here uniformly and no
 * consumer needs a null-game fallback chain.
 *
 * `game_id` is the module's single GameOps reference — a plain ULID, null for
 * every kind that is not a video game. There is deliberately **no catalogue
 * read model yet**: its producer does not exist, and neither does its
 * contract (GameOps' SPEC lists no catalogue event even as proposed, and
 * disagrees with D-GOP-04 on the fields). ADR-0024 sanctions shipping the
 * table and the reference now and adding the projection later.
 *
 * `requires_linked_provider` is the linked-account signup rule (design §5.6):
 * a provider everyone who enters or joins a team for this discipline must
 * have linked, checked against `LinkedIdentity`. Null means no requirement.
 *
 * @property string $id
 * @property string $brand_id
 * @property DisciplineKind $kind
 * @property string $name
 * @property string $slug
 * @property string|null $game_id
 * @property int $avg_match_minutes
 * @property int $team_size
 * @property bool $allow_draws
 * @property LinkedProvider|null $requires_linked_provider
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Discipline extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<DisciplineFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'kind', 'name', 'slug', 'game_id', 'avg_match_minutes', 'team_size', 'allow_draws', 'requires_linked_provider',
    ];

    /** A solo discipline is one where a "team" is a single person (D-CMP-13). */
    public function isSolo(): bool
    {
        return $this->team_size === 1;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => DisciplineKind::class,
            'avg_match_minutes' => 'integer',
            'team_size' => 'integer',
            'allow_draws' => 'boolean',
            'requires_linked_provider' => LinkedProvider::class,
        ];
    }

    protected static function newFactory(): DisciplineFactory
    {
        return DisciplineFactory::new();
    }
}
