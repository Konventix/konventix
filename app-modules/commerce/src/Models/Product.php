<?php

declare(strict_types=1);

namespace Konventix\Commerce\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Konventix\Commerce\Database\Factories\ProductFactory;
use Konventix\Commerce\Enums\PriceDisplay;
use Konventix\Commerce\Enums\TaxClass;
use Konventix\Platform\Models\Concerns\BelongsToBrand;

/**
 * The shop's catalogue entry (ADR-0047, roadmap slice 24) — merch sold
 * independent of a registration. `event_id` is an optional plain ULID
 * (ADR-0014): a product may tie to one edition, e.g. "this year's shirt",
 * but Commerce never reads Event's tables for it — the id is display-only.
 *
 * Stock lives on `ProductVariant`, never here: a product with no size or
 * colour choices still gets exactly one variant (the "implicit" variant,
 * Task 7's `Action`), so every purchasable thing has one stock counter to
 * lock.
 *
 * @property string $id
 * @property string $brand_id
 * @property string|null $event_id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $image_media_id
 * @property TaxClass $tax_class
 * @property numeric-string $unit_amount_net
 * @property PriceDisplay $price_display
 * @property Carbon|null $published_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Product extends Model
{
    use BelongsToBrand;

    /** @use HasFactory<ProductFactory> */
    use HasFactory, HasUlids;

    protected $fillable = [
        'event_id',
        'name',
        'slug',
        'description',
        'image_media_id',
        'tax_class',
        'unit_amount_net',
        'price_display',
        'published_at',
    ];

    /**
     * Within-module foreign key — a real FK and relation are fine
     * (ADR-0014 only forbids them across module boundaries). Ordered by
     * `position` so the storefront and admin console never need to sort
     * client-side.
     *
     * @return HasMany<ProductVariant, $this>
     */
    public function variants(): HasMany
    {
        return $this->hasMany(ProductVariant::class)->orderBy('position');
    }

    /**
     * @param  Builder<Product>  $query
     * @return Builder<Product>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * True when every variant has nothing left. Task 7's implicit-variant
     * rule guarantees a real product always carries at least one variant, so
     * this is never asked to judge an empty set in practice.
     */
    public function isSoldOut(): bool
    {
        return $this->variants->every(fn (ProductVariant $variant): bool => $variant->remaining() === 0);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'tax_class' => TaxClass::class,
            'unit_amount_net' => 'decimal:2',
            'price_display' => PriceDisplay::class,
            'published_at' => 'datetime',
        ];
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }
}
