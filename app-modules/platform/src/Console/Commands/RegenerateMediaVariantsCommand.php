<?php

declare(strict_types=1);

namespace Konventix\Platform\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Konventix\Platform\Media\ThumbnailGenerator;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\MediaItem;
use Konventix\Platform\Storage\DiskResolver;
use Throwable;

/**
 * Backfills the media library's generated variants (ADR-0035 "generated
 * variants", ADR-0043).
 *
 * A variant is cut at upload, so every row uploaded before a variant
 * existed carries none of it — `ticket_banner` (2026-09-21) is the first
 * such case: consumers fall back to the original, which for the ticket PDF
 * means the whole picture base64-inlined on every page. This is the sweep
 * that fills them in afterwards, and it is the post-deploy step named in
 * `docs/operations.md` for 0.7.0.
 *
 * Three properties it is built around:
 *
 * - **Every brand at once.** A console run resolves no brand (ADR-0029) and
 *   `BrandScope` hardens brand-owned queries to nothing there, so the walk
 *   is `MediaItem::withoutBrandScope()` — and nothing here stamps a brand
 *   onto anything: no row is created, only existing ones are rewritten in
 *   place, each in the brand it already belongs to. `--brand` narrows the
 *   walk to one of them by ULID or slug.
 * - **Idempotent.** By default only the variants an item is actually
 *   missing are cut (`--missing`, on unless `--missing=false` asks for a
 *   rewrite), and a row is saved only when the map genuinely changed — so a
 *   second run writes nothing, and a picture too small for a variant
 *   (`ThumbnailGenerator` never upscales) is not re-encoded on every pass
 *   just because the key stays absent.
 * - **Nothing is invented.** Only the variants `ThumbnailGenerator` knows
 *   are accepted for `--only`, and only raster rows are walked at all: a
 *   PDF or an SVG has no variants by definition and is not a backlog.
 *
 * `variants_generated_at` moves to now on a row this run actually filled —
 * it means "processed with what the generator knows today", which is
 * exactly what changed.
 */
class RegenerateMediaVariantsCommand extends Command
{
    protected $signature = 'media:regenerate-variants
        {--only=* : Generate only these variants (thumb, ticket_banner); comma-separated or repeated. Defaults to all}
        {--brand= : Restrict the walk to one brand, by ULID or slug}
        {--missing=true : Only cut the variants an item does not have; --missing=false rewrites them}';

    protected $description = 'Backfill the media library\'s generated variants for existing uploads (ADR-0035)';

    public function handle(ThumbnailGenerator $thumbnails, DiskResolver $disks): int
    {
        $requested = $this->requestedVariants();

        if ($requested === null) {
            return self::FAILURE;
        }

        $brandId = $this->resolveBrandId();

        if ($brandId === false) {
            return self::FAILURE;
        }

        // `--missing` is VALUE_OPTIONAL: bare `--missing` arrives as null and
        // means what its name says.
        $missingOnly = filter_var($this->option('missing') ?? true, FILTER_VALIDATE_BOOL);

        $scanned = 0;
        $filled = 0;
        $written = [];
        $failed = 0;

        $query = MediaItem::withoutBrandScope()
            ->whereIn('mime_type', ThumbnailGenerator::RASTER_MIMES)
            ->when($brandId !== null, fn ($builder) => $builder->where('brand_id', $brandId));

        $query->chunkById(100, function (Collection $items) use ($thumbnails, $disks, $requested, $missingOnly, &$scanned, &$filled, &$written, &$failed): void {
            foreach ($items as $item) {
                $scanned++;

                $wanted = $missingOnly
                    ? array_values(array_diff($requested, array_keys($item->variants)))
                    : $requested;

                if ($wanted === []) {
                    continue;
                }

                try {
                    $cut = $thumbnails->generate($disks->filesystem($item->disk), $item->path, $item->mime_type, $wanted);
                } catch (Throwable $exception) {
                    $failed++;
                    $this->components->warn(sprintf('%s: %s', $item->getKey(), $exception->getMessage()));

                    continue;
                }

                $next = [...$item->variants, ...$cut];

                // Nothing new — a picture too small for the band, or a
                // rewrite that produced the same paths. Leave the stamp
                // alone so a re-run stays a no-op.
                if ($cut === [] || $next === $item->variants) {
                    continue;
                }

                $item->forceFill([
                    'variants' => $next,
                    'variants_generated_at' => now(),
                ])->save();

                $filled++;

                foreach (array_keys($cut) as $variant) {
                    $written[$variant] = ($written[$variant] ?? 0) + 1;
                }
            }
        });

        $this->components->twoColumnDetail('Items walked', (string) $scanned);
        $this->components->twoColumnDetail('Items filled', (string) $filled);

        foreach ($requested as $variant) {
            $this->components->twoColumnDetail(sprintf('  %s written', $variant), (string) ($written[$variant] ?? 0));
        }

        if ($failed > 0) {
            $this->components->error(sprintf('%d item(s) could not be read.', $failed));

            return self::FAILURE;
        }

        $this->components->info($filled === 0 ? 'Nothing to backfill.' : sprintf('Backfilled %d item(s).', $filled));

        return self::SUCCESS;
    }

    /**
     * The variants asked for, or null when one of them is not a variant this
     * deployment knows how to cut — a typo must stop the run rather than
     * quietly back up nothing.
     *
     * @return list<string>|null
     */
    private function requestedVariants(): ?array
    {
        /** @var list<string> $raw */
        $raw = (array) $this->option('only');

        $names = collect($raw)
            ->flatMap(fn (string $value): array => explode(',', $value))
            ->map(fn (string $value): string => trim($value))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return ThumbnailGenerator::VARIANTS;
        }

        $unknown = array_diff($names, ThumbnailGenerator::VARIANTS);

        if ($unknown !== []) {
            $this->components->error(sprintf(
                'Unknown variant(s): %s. Known: %s.',
                implode(', ', $unknown),
                implode(', ', ThumbnailGenerator::VARIANTS),
            ));

            return null;
        }

        // Generation order is the generator's, not the flag's.
        return array_values(array_intersect(ThumbnailGenerator::VARIANTS, $names));
    }

    /**
     * The brand to narrow to: null for every brand, false when `--brand`
     * named one that does not exist.
     */
    private function resolveBrandId(): string|false|null
    {
        $needle = $this->option('brand');

        if (! is_string($needle) || $needle === '') {
            return null;
        }

        $brand = Brand::query()
            ->where(fn ($query) => $query->where('id', $needle)->orWhere('slug', $needle))
            ->first();

        if ($brand === null) {
            $this->components->error(sprintf('No brand matches "%s".', $needle));

            return false;
        }

        return $brand->getKey();
    }
}
