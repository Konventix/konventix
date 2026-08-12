<?php

declare(strict_types=1);

namespace Konventix\Registration\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Konventix\Platform\Models\Concerns\BelongsToBrand;
use Konventix\Registration\Data\GrantData;
use Konventix\Registration\Database\Factories\RegistrationAmendmentFactory;
use Konventix\Registration\Enums\GrantKind;
use Spatie\LaravelData\DataCollection;
use Spatie\LaravelData\Support\EloquentCasts\DataCollectionEloquentCast;

/**
 * One delta applied to an already-confirmed registration (ADR-0036): the
 * grants that were added, and — when the addition costs money — the lines
 * that were charged for them. Created only by `AmendRegistration`.
 *
 * Its ULID is load-bearing in two other modules, which is why this is a
 * table and not a bare event:
 *
 * - Commerce keys order idempotency on it (`orders.request_key`), so one
 *   registration can carry N orders without a replay creating an N+1st.
 * - Admission keys delta issuance on it (`admissions.amendment_id`), so
 *   "already issued?" is asked per amendment instead of per registration.
 *
 * `lines` is null for a free amendment — crew badges that come with an
 * exhibitor's package add admissions without adding money, and ADR-0036's
 * whole point is that this must not disturb the original invoice.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $registration_id
 * @property DataCollection<int, GrantData> $grants
 * @property list<array{label: string, quantity: int, unit_amount_net: string, tax_class: string}>|null $lines
 * @property string|null $note
 * @property Carbon $amended_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class RegistrationAmendment extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<RegistrationAmendmentFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'registration_id',
        'grants',
        'lines',
        'note',
        'amended_at',
    ];

    /**
     * @return BelongsTo<Registration, $this>
     */
    public function registration(): BelongsTo
    {
        return $this->belongsTo(Registration::class);
    }

    /**
     * How many admission units this amendment added — the number of quota
     * units it consumed, and so the number `ReleasesQuotas` must give back
     * when the registration is cancelled or rejected.
     */
    public function admissionUnits(): int
    {
        return (int) $this->grants
            ->toCollection()
            ->filter(fn (GrantData $grant): bool => $grant->kind === GrantKind::Admission)
            ->sum(fn (GrantData $grant): int => $grant->quantity);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'grants' => DataCollectionEloquentCast::class.':'.GrantData::class,
            'lines' => 'array',
            'amended_at' => 'datetime',
        ];
    }

    protected static function newFactory(): RegistrationAmendmentFactory
    {
        return RegistrationAmendmentFactory::new();
    }
}
