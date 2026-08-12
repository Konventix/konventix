<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Data\PriceDeclarationData;
use Konventix\Registration\Database\Factories\RegistrationTypeFactory;
use Konventix\Registration\Enums\ConfirmationGate;
use Konventix\Registration\Enums\RegistrationApproval;
use Konventix\Registration\Enums\RegistrationSettlement;
use Konventix\Registration\Enums\RegistrationVisibility;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\EloquentCasts\DataCollectionEloquentCast;

/**
 * The offer, described by explicit axes instead of implicit assumptions
 * (ADR-0005, SPEC.md "Owns" — `RegistrationType`): `visibility`, `approval`,
 * `settlement`, `confirmation_gate`, and `grants` — how many admissions of
 * which role a confirmed registration of this type is entitled to.
 *
 * `event_id` is a plain ULID with no FK: Event is a different module
 * (ADR-0014). `grants` is a JSON column cast to a typed
 * `Spatie\LaravelData\DataCollection<GrantData>` — never a free-form array
 * (ADR-0009).
 *
 * @property string $id
 * @property string $brand_id
 * @property string $event_id
 * @property string $name
 * @property RegistrationVisibility $visibility
 * @property RegistrationApproval $approval
 * @property RegistrationSettlement $settlement
 * @property ConfirmationGate $confirmation_gate
 * @property DataCollection<int, GrantData> $grants
 * @property PriceDeclarationData|null $price_declaration
 * @property int $terms_days
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RegistrationType extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RegistrationTypeFactory> */
    use HasFactory, HasUlids;

    /**
     * `grants` defaults to an empty collection rather than a nullable one:
     * `DataCollectionEloquentCast` returns null for a null column, and a
     * type with no grants at all (a pure booth-only exhibitor row, say) is a
     * legitimate empty list, not an absent one.
     *
     * @var array<string, mixed>
     */
    protected $attributes = [
        'grants' => '[]',
        'terms_days' => 14,
    ];

    protected $fillable = [
        'event_id',
        'name',
        'visibility',
        'approval',
        'settlement',
        'confirmation_gate',
        'grants',
        'price_declaration',
        'terms_days',
    ];

    /**
     * @return BelongsToMany<Quota, $this>
     */
    public function quotas(): BelongsToMany
    {
        return $this->belongsToMany(Quota::class, 'registration_type_quota');
    }

    /**
     * @return HasMany<Registration, $this>
     */
    public function registrations(): HasMany
    {
        return $this->hasMany(Registration::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'visibility' => RegistrationVisibility::class,
            'approval' => RegistrationApproval::class,
            'settlement' => RegistrationSettlement::class,
            'confirmation_gate' => ConfirmationGate::class,
            'grants' => DataCollectionEloquentCast::class.':'.GrantData::class,
            // Nullable single value object (ADR-0017): null means "no price
            // at all" (settlement: free), never a zero price.
            'price_declaration' => PriceDeclarationData::class,
            'terms_days' => 'integer',
        ];
    }

    protected static function newFactory(): RegistrationTypeFactory
    {
        return RegistrationTypeFactory::new();
    }
}
