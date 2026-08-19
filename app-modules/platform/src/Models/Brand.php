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
use Konventix\Platform\Jobs\PurgeBrandMedia;

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
 * @property array<string, string>|null $theme_tokens_dark
 * @property string|null $reverb_app_key
 * @property string|null $reverb_app_secret
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Brand extends Model
{
    /** @use HasFactory<BrandFactory> */
    use HasFactory, HasUlids;

    protected $fillable = ['name', 'slug', 'currency', 'theme_tokens', 'theme_tokens_dark'];

    protected $hidden = ['reverb_app_secret'];

    /**
     * Disks this brand's media lived on, captured in the `deleting` hook so
     * the purge job still knows where to look once the rows are gone.
     *
     * @var list<string>
     */
    public array $mediaDisksInUse = [];

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

        // Media rows cascade with the brand; the files they name do not, and
        // on object storage those are objects someone keeps paying for
        // (ADR-0043). The disks are collected here, while the rows still
        // exist — afterwards nothing knows where the brand's files lived.
        // The invoice archive is untouched: statutory retention outlives the
        // brand (ADR-0031).
        static::deleting(function (Brand $brand): void {
            $brand->mediaDisksInUse = array_values(array_map(
                static fn (mixed $disk): string => (string) $disk,
                MediaItem::withoutBrandScope()
                    ->where('brand_id', $brand->getKey())
                    ->distinct()
                    ->pluck('disk')
                    ->all(),
            ));
        });

        static::deleted(function (Brand $brand): void {
            if ($brand->mediaDisksInUse === []) {
                return;
            }

            PurgeBrandMedia::dispatch($brand->getKey(), $brand->mediaDisksInUse);
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
     * Semantic design token overrides for light mode, injected server-side
     * into the root template's `:root` block (ADR-0008). Keys are raw token
     * names without the leading `--`.
     *
     * @return array<string, string>
     */
    public function themeTokens(): array
    {
        return $this->theme_tokens ?? [];
    }

    /**
     * The same, for `.dark`. Empty for a brand that has not themed dark mode,
     * which is the common case and renders no `.dark` block at all rather than
     * an empty one.
     *
     * @return array<string, string>
     */
    public function darkThemeTokens(): array
    {
        return $this->theme_tokens_dark ?? [];
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'theme_tokens' => 'array',
            'theme_tokens_dark' => 'array',
        ];
    }

    protected static function newFactory(): BrandFactory
    {
        return BrandFactory::new();
    }
}
