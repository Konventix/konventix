<?php

declare(strict_types=1);

namespace Konventix\Platform\Models;

use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Konventix\Platform\Database\Factories\BrandFactory;

/**
 * A public identity of the operator: its own domains, theme, content, and
 * module activation set (ADR-0001).
 *
 * Brand is the scoping level for the whole application. It is not itself
 * brand-scoped, and it is not a tenant.
 *
 * @property string $id
 * @property string|null $operator_id
 * @property string $name
 * @property string $slug
 * @property string $currency
 * @property array<string, string> $theme_tokens
 * @property string|null $reverb_app_key
 * @property string|null $reverb_app_secret
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'slug', 'currency', 'theme_tokens'];

    protected $hidden = ['reverb_app_secret'];

    /**
     * Creating a brand provisions its websocket application (ADR-0025): the
     * brand's ULID is the Reverb app id, key and secret are generated here.
     * Deliberately a `creating` hook rather than a seeder concern, so every
     * path that makes a brand yields a broadcastable one.
     */
    protected static function booted(): void
    {
        static::creating(function (Brand $brand): void {
            $brand->reverb_app_key ??= Str::random(40);
            $brand->reverb_app_secret ??= Str::random(40);
        });
    }

    /**
     * The hosts this brand answers on. FrankenPHP/Caddy on-demand TLS serves
     * arbitrary brand domains, so this table is the authority on which host
     * maps to which brand.
     *
     * @return HasMany<BrandDomain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(BrandDomain::class);
    }

    /**
     * The legal entity this brand belongs to (ADR-0001). Nullable until
     * operator provisioning exists; the seeder backfills the single operator.
     *
     * @return BelongsTo<Operator, $this>
     */
    public function operator(): BelongsTo
    {
        return $this->belongsTo(Operator::class);
    }

    /**
     * Semantic design token overrides, injected server-side into the root
     * template (ADR-0008). Keys are raw token names without the leading `--`.
     *
     * @return array<string, string>
     */
    public function themeTokens(): array
    {
        return $this->theme_tokens ?? [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme_tokens' => 'array',
        ];
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }
}
