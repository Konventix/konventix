<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Konventix\Competition\Data\MatchHistoryPayload;
use Konventix\Competition\Enums\MatchHistoryKind;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One step in a match's life (design §8.6) — #116's "nachvollziehbare
 * Historie pro Match", delivered without the chat it asked for it inside.
 *
 * Append-only by construction: every writer creates a row and nothing ever
 * updates one, which is why the table carries `created_at` and no
 * `updated_at`. That is also what makes it worth showing to *both sides* as
 * well as the desk — a timeline that could be edited afterwards would settle
 * no argument.
 *
 * `payload` is typed per kind by {@see MatchHistoryPayload} (ADR-0009) rather
 * than being a free-form bag: thirteen kinds that carry different fields is
 * what a timeline is, and the Data object names which field belongs to which
 * kind.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $competition_match_id
 * @property MatchHistoryKind $kind
 * @property string|null $actor_user_id
 * @property array<string, mixed> $payload
 * @property CarbonInterface|null $created_at
 */
class MatchHistoryEntry extends Model
{
    use BelongsToBrand;
    use HasUlids;

    /** Append-only: there is no update to stamp. */
    public const UPDATED_AT = null;

    protected $table = 'competition_match_history';

    protected $fillable = ['competition_match_id', 'kind', 'actor_user_id', 'payload'];

    /**
     * The payload as the typed object that wrote it.
     *
     * Named `payloadData()` rather than `fromPayload()` and the like:
     * laravel-data treats any public static `from*` on a Data class as a
     * magic creator, and the habit of avoiding that prefix for helpers is
     * worth keeping either side of the boundary (.ai/rules/data.md).
     */
    public function payloadData(): MatchHistoryPayload
    {
        return MatchHistoryPayload::ofStoredPayload($this->payload);
    }

    /**
     * @return BelongsTo<CompetitionMatch, $this>
     */
    public function match(): BelongsTo
    {
        return $this->belongsTo(CompetitionMatch::class, 'competition_match_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => MatchHistoryKind::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
