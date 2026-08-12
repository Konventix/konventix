<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\BrandData;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\Operator;

/**
 * Creates a brand with its hosts under an operator. Reverb credentials are
 * provisioned by the model's creating hook (ADR-0025); on-demand TLS starts
 * approving the hosts the moment the rows exist (ADR-0001, ADR-0029).
 *
 * Capability: platform.manage-brands (checked at the route; the full
 * AuthorizingAction dispatcher of ADR-0015 is follow-up work).
 */
final class CreateBrand
{
    public function handle(Operator $operator, BrandData $data): Brand
    {
        return DB::transaction(function () use ($operator, $data): Brand {
            $this->guardUniqueness($data);

            /** @var Brand $brand */
            $brand = $operator->brands()->create([
                'name' => $data->name,
                'slug' => $data->slug,
                'theme_tokens' => [],
            ]);

            foreach (array_values($data->hosts) as $index => $host) {
                $brand->domains()->create([
                    'host' => mb_strtolower($host),
                    'is_primary' => $index === 0,
                ]);
            }

            return $brand;
        });
    }

    private function guardUniqueness(BrandData $data): void
    {
        if (Brand::query()->where('slug', $data->slug)->exists()) {
            throw ValidationException::withMessages(['slug' => 'This slug is already taken.']);
        }

        $taken = BrandDomain::query()
            ->whereIn('host', array_map(mb_strtolower(...), $data->hosts))
            ->pluck('host');

        if ($taken->isNotEmpty()) {
            throw ValidationException::withMessages([
                'hosts' => 'Already answering on: '.$taken->implode(', '),
            ]);
        }
    }
}
