<?php

declare(strict_types=1);

namespace Konventix\Community\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Konventix\Community\Database\Factories\EventPresenceFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The admission badge (design §4.5, ADR-0067 decision 2): whether the door
 * has seen this person for this event, and which way they last scanned.
 * Projected from Admission's public participant events by
 * `Listeners\ProjectAdmissionBadge` — Community may not read Admission's
 * own `admission_event_presence` table (ADR-0004).
 *
 * This is **displayed, never enforced** (ADR-0067 decision 2): nothing in
 * Community grants, refuses or gates anything on `checked_in`. The access
 * gate for the shoutbox is Platform's `ActiveEventContext`, which asks
 * Admission's own answer.
 *
 * Brand-owned (ADR-0010): `BelongsToBrand` + `HasUlids`. `event_id`/
 * `user_id` are plain ULID columns naming Admission's event and Identity's
 * account, no FK (ADR-0014).
 *
 * Only `updated_at` is tracked (design §4.5) — a badge is current state,
 * not a history, so `CREATED_AT` is switched off.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $user_id
 * @property bool $checked_in
 * @property string|null $last_direction
 * @property CarbonImmutable|null $updated_at
 */
class EventPresence extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<EventPresenceFactory> */
    use HasFactory, HasUlids;

    /**
     * A badge is current state, not a history — no `created_at` (design §4.5).
     */
    public const CREATED_AT = null;

    protected $table = 'community_event_presence';

    // `brand_id` is deliberately NOT fillable — it is never mass-assigned.
    // `BelongsToBrand`'s invariant (.ai/rules/models.md) is that the only
    // way to set it is the ambient-context `creating` hook or the named
    // `withoutBrandScope()` escape hatch; a mass-assignable `brand_id` would
    // let any caller's array override it. `ProjectAdmissionBadge` writes the
    // event's brand via `forceFill()` instead.
    protected $fillable = [
        'event_id',
        'user_id',
        'checked_in',
        'last_direction',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'checked_in' => 'boolean',
            'updated_at' => 'immutable_datetime',
        ];
    }

    protected static function newFactory(): EventPresenceFactory
    {
        return EventPresenceFactory::new();
    }
}
