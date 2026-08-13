<?php

declare(strict_types=1);

namespace Konventix\Staffing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Staffing\Database\Factories\AssignmentFactory;
use Konventix\Staffing\Enums\AssignmentState;

/**
 * One person on one shift role.
 *
 * `user_id` is a plain ULID: Identity owns users, and Identity's own SPEC
 * lists "shift assignments, team membership, staffer profile" among the
 * things that explicitly do not go on `User` (ADR-0004, ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $shift_id
 * @property string $shift_role_id
 * @property string $user_id
 * @property AssignmentState $state
 * @property Carbon|null $offered_at
 * @property Carbon|null $responded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Assignment extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<AssignmentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['shift_id', 'shift_role_id', 'user_id', 'state', 'offered_at', 'responded_at'];

    /**
     * @return BelongsTo<ShiftRole, $this>
     */
    public function shiftRole(): BelongsTo
    {
        return $this->belongsTo(ShiftRole::class);
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'state' => AssignmentState::class,
            'offered_at' => 'datetime',
            'responded_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssignmentFactory
    {
        return AssignmentFactory::new();
    }
}
