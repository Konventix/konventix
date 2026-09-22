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

    /**
     * The second lifetime (ADR-0047 point 4, Task 11 fix round 1): once
     * `PlaceOrderFromCart` moves a cart to `checked_out`, `expires_at` is
     * reset to this many minutes out instead — the window a payer gets to
     * finish a hosted checkout before `ExpireCarts`' second pass gives up
     * on it, cancels the still-pending order, and releases what it held.
     * Longer than Stripe Checkout's 30-minute minimum session lifetime;
     * Mollie's and Unzer's hosted pages are comparable. A cart whose order
     * is *paid* is never touched by that pass, however long it has been
     * `checked_out`.
     */
    public const int CHECKOUT_GRACE_MINUTES = 60;

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
     * **What the cart holds**, which since #219 is not the same thing as
     * every `cart_lines` row pointing at it: a line inside its undo window
     * carries `removed_at` and is filtered out here. That one filter is
     * what makes the soft removal indistinguishable from the delete it
     * replaced for every existing reader — the page, the totals, the cart
     * badge (`Cart\CartSummary`), the checkout (`PlaceOrderFromCart`) and
     * the line lookup behind the quantity and remove routes, which all go
     * through this relation.
     *
     * @return HasMany<CartLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(CartLine::class)->whereNull('removed_at')->orderBy('position');
    }

    /**
     * The other side of that filter (#219): the lines the buyer has removed
     * and may still put back. Read by the cart page, which offers the undo,
     * and by the two Actions that close the window —
     * `Actions\ResolveRemovedCartLines` at checkout and
     * `Actions\ExpireCarts` when the hold runs out.
     *
     * @return HasMany<CartLine, $this>
     */
    public function removedLines(): HasMany
    {
        return $this->hasMany(CartLine::class)->whereNotNull('removed_at')->orderBy('position');
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
