<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Enums\PolicyVersionState;
use Konventix\Compliance\Models\Policy;
use Konventix\Compliance\Models\PolicyVersion;

/**
 * Renames a policy, or moves it to another slug.
 *
 * The slug freezes once a version of this policy has been published. A
 * published policy's URL is what a consent record, an imprint link and an
 * emailed "our terms have changed" notice all point at; changing it later
 * breaks every one of them, and the record of what a person agreed to must
 * stay reachable. The display name stays editable — a typo in a heading is
 * not a broken reference.
 */
final class UpdatePolicy
{
    public function __invoke(Policy $policy, PolicyData $data): Policy
    {
        return DB::transaction(function () use ($policy, $data): Policy {
            if ($data->slug !== $policy->slug && $this->hasEverPublished($policy)) {
                throw new InvalidArgumentException(
                    'The slug of a policy that has published a version is frozen: consent records and published links resolve through it.',
                );
            }

            $policy->forceFill([
                'name' => $data->name,
                'slug' => $data->slug,
            ])->save();

            return $policy->refresh();
        });
    }

    private function hasEverPublished(Policy $policy): bool
    {
        return PolicyVersion::withoutBrandScope()
            ->where('policy_id', $policy->getKey())
            ->whereIn('state', [PolicyVersionState::Published, PolicyVersionState::Superseded])
            ->exists();
    }
}
