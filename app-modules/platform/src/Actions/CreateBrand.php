<?php

declare(strict_types=1);

namespace Konventix\Platform\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Platform\Data\BrandData;
use Konventix\Platform\Events\BrandCreated;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Models\BrandDomain;
use Konventix\Platform\Models\Operator;

/**
 * Creates a brand with its hosts under an operator. Reverb credentials are
 * provisioned by the model's creating hook (ADR-0025); on-demand TLS starts
 * approving the hosts the moment the rows exist (ADR-0001, ADR-0029).
 *
 * `BrandCreated` is raised here, once the transaction has committed, so a
 * module can provision whatever a brand cannot function without before its
 * first visitor arrives — Admission mints the brand's entrance signing key
 * on it (#287), without which the first ticket download of every brand was
 * a 500. Scalars only (ADR-0004).
 *
 * Capability: platform.manage-brands (checked at the route; the full
 * AuthorizingAction dispatcher of ADR-0015 is follow-up work).
 */
final class CreateBrand
{
    public function __construct(private readonly MaterializeDefaultRoles $materialize) {}

    public function handle(Operator $operator, BrandData $data): Brand
    {
        $brand = DB::transaction(function () use ($operator, $data): Brand {
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

        // Kernel and Core modules are always on, so their defaults exist from
        // the first minute (ADR-0045). Outside the transaction: it sets its
        // own brand context and team id, and nothing here depends on it.
        $this->materialize->handle($brand);

        // After the commit, for the same reason: a listener that writes
        // brand-owned rows of its own must not have them rolled back
        // underneath it, and "a brand was created" is not true until the row
        // is.
        event(new BrandCreated(
            brandId: $brand->getKey(),
            operatorId: (string) $brand->operator_id,
            slug: $brand->slug,
            name: $brand->name,
        ));

        return $brand;
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
