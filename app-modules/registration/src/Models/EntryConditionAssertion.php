<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Database\Factories\EntryConditionAssertionFactory;
use Konventix\Registration\Enums\EntryConditionAssertionState;
use Konventix\Registration\Enums\EntryConditionDecisionSource;
use Konventix\Registration\Enums\EntryConditionReasonCode;
use Konventix\Registration\Enums\EntryConditionVerification;

/**
 * One declaration, and whatever was later made of it (spec §4.3).
 *
 * Exactly one row per `(registration, entry_condition)`, whatever the
 * registration's `quantity` (D-REG-20): the registrant declares once, for the
 * contractual act. The door checks per person instead, because the label
 * rides on every admission.
 *
 * `assertion_text_snapshot` and `verification_snapshot` are the reason a
 * condition stays freely editable after its type has sold (T-6, D-REG-19):
 * the wording may change, the evidence may not change under the person who
 * gave it — the same principle that binds a consent to a policy *version*.
 *
 * `subject_user_id` and `decided_by_user_id` are Identity's ULIDs with no FK
 * (ADR-0014). `reason_code` is a bounded enum, never a sentence about a
 * person.
 *
 * A `refused` row records a fact. It never revokes an admission and never
 * reaches Commerce (T-8).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property string $entry_condition_id
 * @property string $subject_user_id
 * @property Carbon $asserted_at
 * @property string $assertion_text_snapshot
 * @property EntryConditionVerification $verification_snapshot
 * @property EntryConditionAssertionState $state
 * @property string|null $decided_by_user_id
 * @property Carbon|null $decided_at
 * @property EntryConditionDecisionSource|null $decided_via
 * @property EntryConditionReasonCode|null $reason_code
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntryConditionAssertion extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntryConditionAssertionFactory> */
    use HasFactory, HasUlids;

    protected $table = 'registration_entry_condition_assertions';

    protected $fillable = [
        'registration_id',
        'entry_condition_id',
        'subject_user_id',
        'asserted_at',
        'assertion_text_snapshot',
        'verification_snapshot',
        'state',
        'decided_by_user_id',
        'decided_at',
        'decided_via',
        'reason_code',
    ];

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * @return BelongsTo<EntryCondition, $this>
     */
    public function entryCondition(): BelongsTo
    {
        return $this->belongsTo(EntryCondition::class, 'entry_condition_id');
    }

    /**
     * Whether somebody has already judged this declaration. An undecided row
     * is what `operator_checked` puts on the console worklist.
     */
    public function isDecided(): bool
    {
        return $this->state !== EntryConditionAssertionState::Asserted;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'asserted_at' => 'datetime',
            'verification_snapshot' => EntryConditionVerification::class,
            'state' => EntryConditionAssertionState::class,
            'decided_at' => 'datetime',
            'decided_via' => EntryConditionDecisionSource::class,
            'reason_code' => EntryConditionReasonCode::class,
        ];
    }

    protected static function newFactory(): EntryConditionAssertionFactory
    {
        return EntryConditionAssertionFactory::new();
    }
}
