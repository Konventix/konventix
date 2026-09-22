<?php

declare(strict_types=1);

namespace Konventix\Competition\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Competition\Database\Factories\CompetitionFactory;
use Konventix\Competition\Enums\CheckInMode;
use Konventix\Competition\Enums\CompetitionStatus;
use Konventix\Competition\Enums\ProofPolicy;
use Konventix\Competition\Enums\ResultSubmissionMode;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One contest at one event, running one discipline.
 *
 * The *format* is not here: it belongs to the stage (design §7.1). A
 * competition is one or more stages, each with its own format and typed
 * settings, and the console creates the competition and its first stage in
 * one go. `competitions.format` was 10a's single-flat-list shape and is gone.
 *
 * `status` (D-CMP-15, design §4) is the lifecycle, enforced by
 * `CompetitionStatus::allowedTransitions()` and moved only by the lifecycle
 * Actions. `published_at` survives as the timestamp of the publish
 * transition — never as the state itself, which is what it used to stand in
 * for.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $discipline_id
 * @property string $event_id
 * @property string $name
 * @property string $slug
 * @property CompetitionStatus $status
 * @property string|null $description
 * @property string|null $rules
 * @property int|null $max_entrants
 * @property CheckInMode $check_in_mode
 * @property int $check_in_opens_minutes_before
 * @property bool $requires_event_check_in
 * @property ResultSubmissionMode $result_submission_mode
 * @property ProofPolicy $proof_policy
 * @property Carbon|null $signup_opens_at
 * @property Carbon|null $signup_closes_at
 * @property Carbon|null $starts_at
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Competition extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CompetitionFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'discipline_id', 'event_id', 'name', 'slug', 'status', 'description', 'rules',
        'max_entrants', 'signup_opens_at', 'signup_closes_at', 'starts_at', 'published_at',
        'check_in_mode', 'check_in_opens_minutes_before', 'requires_event_check_in',
        'result_submission_mode', 'proof_policy',
    ];

    /**
     * Whether signup is open right now (design §4): the state says whether it
     * *may* be, the window says whether it *is*.
     *
     * An unset window means "no window", not "closed" — an organizer who
     * never set dates wants people to be able to sign up, not a silently shut
     * door. Only `Published` derives signup from the window at all;
     * `SignupClosed` is shut however the dates read, which is the point of
     * having the state.
     */
    public function signupIsOpen(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if (! $this->status->mayAcceptSignups()) {
            return false;
        }

        if ($this->signup_opens_at !== null && $now->lessThan($this->signup_opens_at)) {
            return false;
        }

        return $this->signup_closes_at === null || $now->lessThan($this->signup_closes_at);
    }

    /**
     * When a self-service check-in opens: `check_in_opens_minutes_before`
     * minutes before `starts_at` (design §6).
     *
     * **Null when `starts_at` is unset**, and that means the window never
     * opens rather than "always open". The window is defined relative to the
     * start; without a start there is no anchor, and a check-in weeks early
     * answers none of the question check-in exists to ask. An organizer who
     * wants self-service sets a start time — and the desk can check teams in
     * whatever the mode, so nobody is locked out meanwhile.
     */
    public function selfServiceCheckInOpensAt(): ?CarbonInterface
    {
        if ($this->starts_at === null) {
            return null;
        }

        return $this->starts_at->copy()->subMinutes($this->check_in_opens_minutes_before);
    }

    /**
     * Whether a captain may check their team in right now (design §6).
     *
     * Three conditions, in the order they fail for different reasons: the
     * mode has to allow self-service at all, the competition must not have
     * started yet — the window "closes at `start`", and `Finished` and
     * `Cancelled` are past that — and the clock must have reached the
     * opening moment. The edge is inclusive: at exactly `starts_at` minus
     * the lead minutes the window is open.
     *
     * There is no upper bound in the clock, on purpose. What shuts the window
     * is the organizer starting the competition, not `starts_at` passing —
     * a tournament that begins twenty minutes late must not lock out the team
     * jogging in from the car park.
     */
    public function selfServiceCheckInIsOpen(?CarbonInterface $now = null): bool
    {
        if (! $this->check_in_mode->allowsSelfService()) {
            return false;
        }

        if (in_array($this->status, [
            CompetitionStatus::Running,
            CompetitionStatus::Finished,
            CompetitionStatus::Cancelled,
        ], true)) {
            return false;
        }

        $opensAt = $this->selfServiceCheckInOpensAt();

        return $opensAt !== null && ! ($now ?? now())->lessThan($opensAt);
    }

    /**
     * @return BelongsTo<Discipline, $this>
     */
    public function discipline(): BelongsTo
    {
        return $this->belongsTo(Discipline::class);
    }

    /**
     * @return HasMany<Entrant, $this>
     */
    public function entrants(): HasMany
    {
        return $this->hasMany(Entrant::class);
    }

    /**
     * The competition's stages in running order (design §7.3). One today —
     * the console creates exactly one — but the model is the multi-stage one
     * from the start, so a later group-into-playoff competition is stages
     * rather than a migration.
     *
     * @return HasMany<Stage, $this>
     */
    public function stages(): HasMany
    {
        return $this->hasMany(Stage::class)->orderBy('sequence');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CompetitionStatus::class,
            'max_entrants' => 'integer',
            'check_in_mode' => CheckInMode::class,
            'check_in_opens_minutes_before' => 'integer',
            'requires_event_check_in' => 'boolean',
            'result_submission_mode' => ResultSubmissionMode::class,
            'proof_policy' => ProofPolicy::class,
            'signup_opens_at' => 'datetime',
            'signup_closes_at' => 'datetime',
            'starts_at' => 'datetime',
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CompetitionFactory
    {
        return CompetitionFactory::new();
    }
}
