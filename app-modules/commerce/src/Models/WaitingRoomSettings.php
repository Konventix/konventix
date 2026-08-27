<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\WaitingRoomSettingsFactory;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * Per-brand waiting-room settings (ADR-0047 point 5): how many arrivals
 * `AdmitFromWaitingRoom` admits per call, and how long an admitted ticket
 * stays admitted before `CommerceWaitingRoomGate` sends it back to the
 * queue. `forBrand()` hands back an unsaved defaulted instance when
 * nothing was configured yet, the same idiom as
 * `BookkeepingSettings::forOperator()` — the door still works with no row
 * at all.
 *
 * @property string $id
 * @property string $brand_id
 * @property int $admit_per_interval
 * @property int $admission_ttl_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class WaitingRoomSettings extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<WaitingRoomSettingsFactory> */
    use HasFactory, HasUlids;

    protected $table = 'waiting_room_settings';

    protected $fillable = [
        'admit_per_interval',
        'admission_ttl_minutes',
    ];

    protected $attributes = [
        'admit_per_interval' => 50,
        'admission_ttl_minutes' => 30,
    ];

    /**
     * `withoutBrandScope()` — the admin host resolves no brand (ADR-0029),
     * so a plain query would see zero rows even for a brand that does have
     * settings saved.
     */
    public static function forBrand(string $brandId): self
    {
        $existing = self::withoutBrandScope()->where('brand_id', $brandId)->first();

        if ($existing !== null) {
            return $existing;
        }

        $settings = new self;
        $settings->brand_id = $brandId;

        return $settings;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'admit_per_interval' => 'integer',
            'admission_ttl_minutes' => 'integer',
        ];
    }

    protected static function newFactory(): WaitingRoomSettingsFactory
    {
        return WaitingRoomSettingsFactory::new();
    }
}
