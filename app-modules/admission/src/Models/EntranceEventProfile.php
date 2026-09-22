<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\EntranceEventProfileFactory;
use Konventix\Admission\Enums\ReentryPolicy;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Admission's own copy of the two Event facts the door must decide without a
 * network round trip (ADR-0033): the event-day calendar and the re-entry
 * policy.
 *
 * This is a read model of foreign data, the pattern `.ai/rules/src.md`
 * prescribes — Admission may not `use` Event's models (ADR-0004), so it
 * listens to `EventDaysChanged` and `EventReentryPolicyChanged` and keeps its
 * own row. Both payloads carry their complete current value, so the
 * projection replaces rather than patches and a replay converges.
 *
 * `ReentryPolicy` is re-declared in this module rather than imported from
 * Event for the same reason the grant kind is a string literal in
 * `IssueAdmissionsForRegistration`: the *value* is the contract, not the
 * class.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property ReentryPolicy $reentry_policy
 * @property list<array{day_id: string, date: string, sequence: int, label: string|null, day_ends_at?: string|null}>|null $days
 * @property int|null $poll_seconds
 * @property int|null $stale_after_seconds
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class EntranceEventProfile extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EntranceEventProfileFactory> */
    use HasFactory, HasUlids;

    /**
     * ADR-0033 requires "frequently-synced" to be given a number before
     * build, without setting one. These are it: a 30s poll bounds the
     * cross-device duplicate window to roughly half a minute, and after 15
     * minutes without a successful sync the device declares itself stale and
     * degrades to signature-only — never to a closed door.
     */
    public const int DEFAULT_POLL_SECONDS = 30;

    public const int DEFAULT_STALE_AFTER_SECONDS = 900;

    protected $fillable = [
        'event_id',
        'reentry_policy',
        'days',
        'poll_seconds',
        'stale_after_seconds',
    ];

    public function pollSeconds(): int
    {
        return $this->poll_seconds ?? self::DEFAULT_POLL_SECONDS;
    }

    public function staleAfterSeconds(): int
    {
        return $this->stale_after_seconds ?? self::DEFAULT_STALE_AFTER_SECONDS;
    }

    /**
     * The event day covering the given moment, or null when the calendar has
     * no day for it — which is a rejection at the door, not an error: an
     * admission is valid *on a day*, and a scan outside every day has no day
     * to be valid on (D-ADM-04).
     *
     * Dates are compared as plain Y-m-d strings in the event's own local
     * frame; ADR-0027 keeps event time as wall-clock local plus a timezone,
     * so converting through UTC here would reintroduce exactly the drift that
     * ADR avoids.
     *
     * @return array{day_id: string, date: string, sequence: int, label: string|null, day_ends_at?: string|null}|null
     */
    public function dayCovering(CarbonInterface $moment): ?array
    {
        $date = $moment->format('Y-m-d');

        foreach ($this->days ?? [] as $day) {
            if ($day['date'] === $date) {
                return $day;
            }
        }

        return null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'reentry_policy' => ReentryPolicy::class,
            'days' => 'array',
        ];
    }

    protected static function newFactory(): EntranceEventProfileFactory
    {
        return EntranceEventProfileFactory::new();
    }
}
