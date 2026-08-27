<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\CartFactory;
use Konventix\Commerce\Enums\CartStatus;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * A person-owned reservation that locks stock as a quota for twenty minutes
 * (ADR-0047): the first Commerce root that belongs to a person from the
 * start, rather than becoming theirs only once an order exists (ADR-0044
 * gave orders a payer; a cart is that same idea one step earlier).
 *
 * `payer_user_id` is a plain ULID (ADR-0014), never an Eloquent relation to
 * Identity — the same reasoning `orders.payer_user_id` already applies.
 * `checked_out_order_id` is likewise a plain ULID, set once Task 11's
 * checkout turns this cart into an `Order`.
 *
 * Every stock-mutating change to this cart's lines calls `touchExpiry()`,
 * so a person actively shopping never loses their reservation mid-edit —
 * only genuine inactivity past `LIFETIME_MINUTES` does.
 *
 * @property string $id
 * @property string $brand_id
 * @property string $payer_user_id
 * @property CartStatus $status
 * @property CarbonImmutable $expires_at
 * @property string|null $checked_out_order_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Cart extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<CartFactory> */
    use HasFactory, HasUlids;

    /**
     * The reservation window (ADR-0047): every `touchExpiry()` call pushes
     * `expires_at` this far into the future.
     */
    public const int LIFETIME_MINUTES = 20;

    protected $fillable = [
        'payer_user_id',
        'status',
        'expires_at',
        'checked_out_order_id',
    ];

    /**
     * Within-module foreign key — a real FK and relation are fine
     * (ADR-0014 only forbids them across module boundaries). Ordered by
     * `position` so the cart page never needs to sort client-side.
     *
     * @return HasMany<CartLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class)->orderBy('position');
    }

    /**
     * @param  Builder<Cart>  $query
     * @return Builder<Cart>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', CartStatus::Open);
    }

    public function isOpen(): bool
    {
        return $this->status === CartStatus::Open;
    }

    /**
     * Pushes the reservation another `LIFETIME_MINUTES` into the future.
     * Called by every Action that changes what this cart holds — adding,
     * changing a quantity, or removing a line — so continued activity keeps
     * the hold alive.
     */
    public function touchExpiry(): void
    {
        $this->expires_at = now()->addMinutes(self::LIFETIME_MINUTES);
        $this->save();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CartStatus::class,
            'expires_at' => 'datetime',
        ];
    }

    protected static function newFactory(): CartFactory
    {
        return CartFactory::new();
    }
}
