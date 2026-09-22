<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Konventix\Platform\Data\ActiveEventData;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One member's admission to one running event, as Platform knows it
 * (design §5.1): a projection of `ParticipantAdmitted` and its siblings,
 * written only by `Listeners\ProjectHubAccess`. Admission owns the truth
 * in `admission_event_presence`; this table is the shell's accelerator,
 * rebuildable by replay, and nothing here is ever the gate itself.
 *
 * `on_site` mirrors D-HUB-02: a badge, never a gate — a member who scanned
 * out keeps their row and their hub. `window_ends_at` null means "open
 * until the event concludes". `event_id`, `user_id` and `admission_id`
 * are other modules' ULIDs (ADR-0014).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $user_id
 * @property string $admission_id
 * @property string|null $event_slug
 * @property string|null $event_name
 * @property string|null $day_label
 * @property CarbonImmutable $admitted_at
 * @property CarbonImmutable|null $window_ends_at
 * @property bool $on_site
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HubAccess extends Model
{
    use BelongsToBrand, HasUlids;

    protected $table = 'platform_hub_access';

    protected $fillable = [
        'event_id',
        'user_id',
        'admission_id',
        'event_slug',
        'event_name',
        'day_label',
        'admitted_at',
        'window_ends_at',
        'on_site',
    ];

    /**
     * The shape the shell and the hub page consume; instants travel as
     * ISO-8601 strings so the cached copy needs no re-hydration.
     */
    public function toActiveEvent(): ActiveEventData
    {
        return new ActiveEventData(
            eventId: $this->event_id,
            eventSlug: $this->event_slug,
            eventName: $this->event_name,
            admissionId: $this->admission_id,
            dayLabel: $this->day_label,
            admittedAt: $this->admitted_at->toIso8601String(),
            windowEndsAt: $this->window_ends_at?->toIso8601String(),
            onSite: $this->on_site,
        );
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admitted_at' => 'immutable_datetime',
            'window_ends_at' => 'immutable_datetime',
            'on_site' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
