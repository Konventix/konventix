<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\EntryConditionFactory;
use Konventix\Registration\Enums\EntryConditionVerification;

/**
 * What a reduced fare rests on (ADR-0066, spec §4.1, D-REG-17): the fact a
 * registrant declares — "I am enrolled as a student" — the document the door
 * will ask for, and how it is meant to be checked.
 *
 * Brand-wide and reusable: one "Student" condition is written once and hung
 * on every student type of every event. `event_id` is nullable and normally
 * null; a value pins a one-off condition to a single event. It is Event's
 * ULID, so no FK (ADR-0014).
 *
 * Two identifiers, both load-bearing outside this module: the ULID is what
 * Compliance stores as a `policy_bindings.target_key`, and `key` is what
 * travels to the door on the admission and onto the ticket PDF. Neither ever
 * becomes a metric label — an operator-defined key is unbounded cardinality
 * (T-11).
 *
 * `assertion_text` is the sentence the registrant ticks, verbatim. It may be
 * reworded at any time, including after the type has sold (D-REG-19): what
 * somebody declared is the snapshot on their `EntryConditionAssertion`, never
 * this column.
 *
 * Archived, never deleted — the assertion FK restricts.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $event_id
 * @property string $key
 * @property string $name
 * @property string|null $description
 * @property string $assertion_text
 * @property string|null $document_hint
 * @property EntryConditionVerification $verification
 * @property Carbon|null $archived_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntryCondition extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntryConditionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'registration_entry_conditions';

    protected $fillable = [
        'event_id',
        'key',
        'name',
        'description',
        'assertion_text',
        'document_hint',
        'verification',
        'archived_at',
    ];

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * The conditions an operator may still hang on a type. Archived rows stay
     * attached to the registrations that asserted them and disappear from
     * every picker — which is the whole difference between archiving and
     * deleting (spec §4.2).
     *
     * @param  Builder<EntryCondition>  $query
     * @return Builder<EntryCondition>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('archived_at');
    }

    /**
     * @param  Builder<EntryCondition>  $query
     * @return Builder<EntryCondition>
     */
    public function scopeArchived(Builder $query): Builder
    {
        return $query->whereNotNull('archived_at');
    }

    /**
     * @return BelongsToMany<RegistrationType, $this>
     */
    public function registrationTypes(): BelongsToMany
    {
        return $this->belongsToMany(RegistrationType::class, 'registration_type_entry_condition')
            ->withPivot('position')
            ->withTimestamps();
    }

    /**
     * @return HasMany<EntryConditionAssertion, $this>
     */
    public function assertions(): HasMany
    {
        return $this->hasMany(EntryConditionAssertion::class, 'entry_condition_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'verification' => EntryConditionVerification::class,
            'archived_at' => 'datetime',
        ];
    }

    protected static function newFactory(): EntryConditionFactory
    {
        return EntryConditionFactory::new();
    }
}
