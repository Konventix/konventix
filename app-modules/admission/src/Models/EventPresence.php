<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Konventix\Admission\Database\Factories\EventPresenceFactory;
use Konventix\Admission\Enums\HubAccessEndReason;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One person's standing at one event (D-HUB-01/02): admitted once, where
 * they last scanned, and until when the live hub stays open to them.
 *
 * `hub_access` is derived but stored, so consumers never do date math;
 * `window_ends_at` null means "open until the event concludes". Nobody
 * outside Admission reads this table — Platform and Community learn of it
 * through `ParticipantAdmitted` and its siblings (design §4.3, ADR-0004).
 * `event_id` and `user_id` are plain ULIDs (ADR-0014).
 *
 * `announced_hub_access` and `announced_direction` are not state, they are
 * the acknowledgement of state: the values the participant events have
 * actually carried out of this module. They move only after the event was
 * dispatched, so a job that dies between the save and the dispatch re-emits
 * on its retry instead of deciding, from the already-saved row, that
 * nothing transitioned (D-HUB-01). Null means *nothing has been announced
 * yet* and is never defaulted to a value on insert: false would assert an
 * announcement that was never made, and a row that ended up `hub_access =
 * false` with a null marker is a row two dispatches died on, which
 * `ProjectEventPresence::unsettled()` has to keep picking up.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $user_id
 * @property string $admission_id
 * @property CarbonImmutable $first_admitted_at
 * @property CarbonImmutable $last_check_in_at
 * @property string $last_direction
 * @property CarbonImmutable|null $window_ends_at
 * @property bool $hub_access
 * @property HubAccessEndReason|null $access_ended_reason
 * @property string|null $day_label
 * @property bool|null $announced_hub_access
 * @property string|null $announced_direction
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class EventPresence extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EventPresenceFactory> */
    use HasFactory, HasUlids;

    protected $table = 'admission_event_presence';

    protected $fillable = [
        'event_id',
        'user_id',
        'admission_id',
        'first_admitted_at',
        'last_check_in_at',
        'last_direction',
        'window_ends_at',
        'hub_access',
        'access_ended_reason',
        'day_label',
        'announced_hub_access',
        'announced_direction',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'first_admitted_at' => 'immutable_datetime',
            'last_check_in_at' => 'immutable_datetime',
            'window_ends_at' => 'immutable_datetime',
            'hub_access' => 'boolean',
            'access_ended_reason' => HubAccessEndReason::class,
            'announced_hub_access' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): EventPresenceFactory
    {
        return EventPresenceFactory::new();
    }
}
