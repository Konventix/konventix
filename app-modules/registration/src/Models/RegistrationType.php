<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
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
 * @property int|null $max_quantity_per_registration
 * @property Carbon|null $sales_start
 * @property Carbon|null $sales_end
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
        // Explicitly null rather than absent, for the reason above:
        // `maxQuantityPerRegistration()` reads it on instances that were
        // created without ever naming it, and `shouldBeStrict()` refuses a
        // missing attribute.
        'max_quantity_per_registration' => null,
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
        'max_quantity_per_registration',
        'sales_start',
        'sales_end',
    ];

    /**
     * How many units of this offer one registration may buy (bugfix round
     * 2026-09-21, Task 19).
     *
     * The column is nullable and **null means one**: a type opts into a
     * larger quantity rather than opting out of it, so every type that
     * predates the column keeps the one-unit behaviour it always had. Every
     * reader goes through here rather than through the raw column, so the
     * null-is-one rule is stated once.
     */
    public function maxQuantityPerRegistration(): int
    {
        return max(1, $this->max_quantity_per_registration ?? 1);
    }

    /**
     * Whether this type may be bought at `$now` (#218, #216 draft i).
     *
     * Both bounds are nullable and null means "no bound", so a type that
     * declares neither is always on sale — which is how every type behaved
     * before the window existed. The start is inclusive and the end is
     * exclusive: a window advertised as "18:00 to 18:00" opens on the tick
     * and closes on the tick, with no second of overlap between two
     * consecutive windows.
     *
     * The bounds are instants, so this comparison needs no timezone: see the
     * migration's docblock for why a sales window is not an ADR-0027
     * wall-clock time.
     */
    public function isOnSale(?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if ($this->sales_start !== null && $now->lessThan($this->sales_start)) {
            return false;
        }

        return $this->sales_end === null || $now->lessThan($this->sales_end);
    }

    /**
     * The SQL half of the same window rule, kept beside `isOnSale()` so the
     * two cannot drift (fix round 1, review finding 4): this module has one
     * window predicate, in two forms, and the landing slot uses this one
     * because it filters in the database.
     *
     * Only the end is filtered. A sale that is over disappears from the
     * landing page; a sale still ahead stays, announced rather than linked,
     * and `isOnSale()` on the row is what tells the two apart (#218).
     *
     * @param  Builder<RegistrationType>  $query
     * @return Builder<RegistrationType>
     */
    public function scopeSaleNotOver(Builder $query): Builder
    {
        return $query->where(fn (Builder $inner) => $inner->whereNull('sales_end')->orWhere('sales_end', '>', now()));
    }

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
            'max_quantity_per_registration' => 'integer',
            'sales_start' => 'datetime',
            'sales_end' => 'datetime',
        ];
    }

    protected static function newFactory(): RegistrationTypeFactory
    {
        return RegistrationTypeFactory::new();
    }
}
