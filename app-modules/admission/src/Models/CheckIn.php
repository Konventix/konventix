<?php

declare(strict_types=1);

namespace Konventix\Admission\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Admission\Database\Factories\CheckInFactory;
use Konventix\Admission\Enums\CheckInResult;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * One scan at a door — accepted or rejected, both recorded.
 *
 * `registration_id` is denormalized from the admission deliberately: ADR-0037
 * blocks a refund on a checked-in admission, and Commerce answers that from
 * its own read model fed by Admission's events — never by reading these
 * tables. Carrying the id here is what lets the emitted events carry it.
 *
 * `scanned_at` and `synced_at` are separate because the offline client
 * (ADR-0033) buffers: a scan can reach the server minutes or hours after it
 * happened, and the audit trail has to say when the person actually walked in.
 *
 * `actor_user_id` is the admin who checked the holder in from the console, null
 * for a door scan; `conditions_checked` is the `[{key, outcome}]` list answered
 * on an accepted check-in (attendee-list design §3.1, ADR-0066 §6.4).
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $admission_id
 * @property string|null $registration_id
 * @property string|null $checkpoint_id
 * @property string|null $device_id
 * @property string|null $actor_user_id
 * @property string|null $event_day_id
 * @property string $direction
 * @property CheckInResult $result
 * @property string|null $reason_code
 * @property list<array{key: string, outcome: string}>|null $conditions_checked
 * @property Carbon $scanned_at
 * @property Carbon|null $synced_at
 * @property string $client_scan_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class CheckIn extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CheckInFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'admission_id',
        'registration_id',
        'checkpoint_id',
        'device_id',
        'event_day_id',
        'direction',
        'result',
        'reason_code',
        'scanned_at',
        'synced_at',
        'client_scan_id',
        'actor_user_id',
        'conditions_checked',
    ];

    /**
     * @return BelongsTo<Admission, $this>
     */
    public function admission(): BelongsTo
    {
        return $this->belongsTo(Admission::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'result' => CheckInResult::class,
            'conditions_checked' => 'array',
            'scanned_at' => 'datetime',
            'synced_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CheckInFactory
    {
        return CheckInFactory::new();
    }
}
