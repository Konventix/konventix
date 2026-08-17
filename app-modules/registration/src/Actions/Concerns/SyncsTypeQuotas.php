<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Konventix\Registration\Models\Quota;
use Konventix\Registration\Models\RegistrationType;

/**
 * Attaches a type to the quotas it draws on. Quotas are a standalone entity
 * referenced by many types (ADR-0005), so this is a pivot sync rather than a
 * column.
 *
 * Both queries escape `BrandScope` against an explicit brand id: on the
 * operator host the scope hardens to zero rows (ADR-0029), where a scoped
 * lookup would report every quota id as foreign and refuse the lot.
 */
trait SyncsTypeQuotas
{
    /**
     * @param  list<string>  $quotaIds
     */
    private function syncQuotas(RegistrationType $type, array $quotaIds): void
    {
        $ids = array_values(array_unique($quotaIds));

        if ($ids !== []) {
            $known = Quota::withoutBrandScope()
                ->where('brand_id', $type->brand_id)
                ->whereKey($ids)
                ->pluck('id')
                ->all();

            if (count($known) !== count($ids)) {
                throw ValidationException::withMessages([
                    'quotaIds' => __('One of those quotas does not belong to this brand.'),
                ]);
            }
        }

        $type->quotas()->sync($ids);
    }
}
