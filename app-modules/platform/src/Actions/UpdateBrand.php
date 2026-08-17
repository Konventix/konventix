<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\UpdateBrandData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;

/**
 * Updates a brand's identity fields and syncs its hosts. The first host in
 * the submitted list is the primary — the same convention `CreateBrand`
 * establishes. Hosts removed here stop being approved for on-demand TLS the
 * moment their rows are gone (ADR-0001).
 */
final class UpdateBrand
{
    public function handle(Brand $brand, UpdateBrandData $data): Brand
    {
        return DB::transaction(function () use ($brand, $data): Brand {
            $this->guardUniqueness($brand, $data);

            $brand->update([
                'name' => $data->name,
                'slug' => $data->slug,
                'currency' => $data->currency,
            ]);

            $hosts = array_values(array_map(mb_strtolower(...), $data->hosts));

            $brand->domains()->whereNotIn('host', $hosts)->delete();

            foreach ($hosts as $index => $host) {
                $brand->domains()->updateOrCreate(
                    ['host' => $host],
                    ['is_primary' => $index === 0],
                );
            }

            return $brand->refresh();
        });
    }

    private function guardUniqueness(Brand $brand, UpdateBrandData $data): void
    {
        if (Brand::query()->where('slug', $data->slug)->whereKeyNot($brand->getKey())->exists()) {
            throw ValidationException::withMessages(['slug' => 'This slug is already taken.']);
        }

        $taken = BrandDomain::query()
            ->whereIn('host', array_map(mb_strtolower(...), $data->hosts))
            ->where('brand_id', '!=', $brand->getKey())
            ->pluck('host');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'hosts' => 'Already answering on: '.$taken->implode(', '),
            ]);
        }
    }
}
