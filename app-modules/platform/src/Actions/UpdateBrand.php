<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\UpdateBrandData;
use Konventix\Platform\Media\MediaLibrary;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\MediaItem;

/**
 * Updates a brand's identity fields and syncs its hosts. The first host in
 * the submitted list is the primary — the same convention `CreateBrand`
 * establishes. Hosts removed here stop being approved for on-demand TLS the
 * moment their rows are gone (ADR-0001).
 */
final class UpdateBrand
{
    public function __construct(private readonly MediaLibrary $media) {}

    public function handle(Brand $brand, UpdateBrandData $data): Brand
    {
        return DB::transaction(function () use ($brand, $data): Brand {
            $this->guardUniqueness($brand, $data);
            $this->guardOwnedMedia($brand, $data);

            $worn = $this->marksWornBy($brand);

            $brand->update([
                'name' => $data->name,
                'slug' => $data->slug,
                'currency' => $data->currency,
                'logo_media_id' => $data->logoMediaId,
                'logo_dark_media_id' => $data->logoDarkMediaId,
                'favicon_media_id' => $data->faviconMediaId,
                // Empty means "follow the operator" (ADR-0068 §4).
                'locale' => $data->locale === '' ? null : $data->locale,
            ]);

            $hosts = array_values(array_map(mb_strtolower(...), $data->hosts));

            $brand->domains()->whereNotIn('host', $hosts)->delete();

            foreach ($hosts as $index => $host) {
                $brand->domains()->updateOrCreate(
                    ['host' => $host],
                    ['is_primary' => $index === 0],
                );
            }

            $this->syncMarkUsages($brand, $worn, $this->marksWornBy($brand));

            return $brand->refresh();
        });
    }

    /**
     * The brand is a media consumer like any other (ADR-0035): each mark it
     * wears is a usage reference, which is what stops the media admin from
     * deleting a logo out from under every page. Diffed as sets rather than
     * per field, because one file may serve as both logo and favicon and a
     * usage is per item — releasing it on the logo's account would drop the
     * favicon's too.
     *
     * @param  list<string>  $before
     * @param  list<string>  $after
     */
    private function syncMarkUsages(Brand $brand, array $before, array $after): void
    {
        foreach (array_diff($before, $after) as $released) {
            $this->media->releaseUsage($released, 'platform', 'brand', $brand->getKey());
        }

        foreach (array_diff($after, $before) as $worn) {
            $this->media->recordUsage($worn, 'platform', 'brand', $brand->getKey());
        }
    }

    /**
     * @return list<string>
     */
    private function marksWornBy(Brand $brand): array
    {
        return array_values(array_unique(array_filter([
            $brand->logo_media_id,
            $brand->logo_dark_media_id,
            $brand->favicon_media_id,
        ])));
    }

    /**
     * A brand may only wear its own media. The library is brand-owned
     * (ADR-0035) and resolution ignores brand scope on purpose, so nothing
     * downstream would notice a ULID borrowed from another brand — and the
     * borrowed file would vanish the day that brand is deleted.
     */
    private function guardOwnedMedia(Brand $brand, UpdateBrandData $data): void
    {
        $fields = [
            'logoMediaId' => $data->logoMediaId,
            'logoDarkMediaId' => $data->logoDarkMediaId,
            'faviconMediaId' => $data->faviconMediaId,
        ];

        $ids = array_filter($fields);

        if ($ids === []) {
            return;
        }

        // The admin host resolves no brand, so BrandScope would harden this
        // to zero rows without the explicit escape (ADR-0029).
        $owned = MediaItem::withoutBrandScope()
            ->whereIn('id', $ids)
            ->where('brand_id', $brand->getKey())
            ->pluck('id')
            ->all();

        foreach ($fields as $field => $id) {
            if ($id !== null && ! in_array($id, $owned, true)) {
                throw ValidationException::withMessages([
                    $field => __('That file belongs to a different brand.'),
                ]);
            }
        }
    }

    private function guardUniqueness(Brand $brand, UpdateBrandData $data): void
    {
        if (Brand::query()->where('slug', $data->slug)->whereKeyNot($brand->getKey())->exists()) {
            throw ValidationException::withMessages(['slug' => __('This slug is already taken.')]);
        }

        $taken = BrandDomain::query()
            ->whereIn('host', array_map(mb_strtolower(...), $data->hosts))
            ->where('brand_id', '!=', $brand->getKey())
            ->pluck('host');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'hosts' => __('Already answering on: :hosts', ['hosts' => $taken->implode(', ')]),
            ]);
        }
    }
}
