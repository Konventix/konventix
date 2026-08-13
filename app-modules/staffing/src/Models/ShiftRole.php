<?php

declare(strict_types=1);

namespace Konventix\Staffing\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Staffing\Database\Factories\ShiftRoleFactory;
use Konventix\Staffing\Enums\AssignmentState;

/**
 * What a shift needs: a named function and how many people of it.
 *
 * `admission_role` is the badge role someone gets on accepting (D-STF-01) —
 * decided here because Staffing knows what a person is rostered as, while
 * Admission only knows what to print.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $shift_id
 * @property string $name
 * @property string $admission_role
 * @property int $required_headcount
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ShiftRole extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ShiftRoleFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['shift_id', 'name', 'admission_role', 'required_headcount'];

    /**
     * How many people currently count towards this role's headcount.
     *
     * Counts every state that occupies a slot, not just accepted ones: an
     * outstanding offer is a slot a lead should not fill twice while they
     * wait for an answer.
     */
    public function filledHeadcount(): int
    {
        return Assignment::withoutBrandScope()
            ->where('brand_id', $this->brand_id)
            ->where('shift_role_id', $this->id)
            ->whereIn('state', array_map(
                fn (AssignmentState $state): string => $state->value,
                array_filter(AssignmentState::cases(), fn (AssignmentState $s): bool => $s->occupiesSlot()),
            ))
            ->count();
    }

    public function isFull(): bool
    {
        return $this->filledHeadcount() >= $this->required_headcount;
    }

    /**
     * @return BelongsTo<Shift, $this>
     */
    public function shift(): BelongsTo
    {
        return $this->belongsTo(Shift::class);
    }

    /**
     * @return HasMany<Assignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(Assignment::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['required_headcount' => 'integer'];
    }

    protected static function newFactory(): ShiftRoleFactory
    {
        return ShiftRoleFactory::new();
    }
}
