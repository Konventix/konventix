<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Compliance\Data\PolicyData;
use Konventix\Compliance\Models\Policy;

/**
 * Creates a policy — the stable slug and name that versions publish under.
 *
 * `brandId` is passed in and force-filled rather than taken from ambient
 * context: the operator console creates policies for a brand it is not
 * currently resolved into (ADR-0029), same as Content's `CreateEntry`.
 *
 * No first version is created here. A policy with no published version is a
 * legitimate intermediate state (the operator is still drafting), and
 * creating an empty draft as a side effect would put a blank legal document
 * one publish-click away.
 */
final class CreatePolicy
{
    public function __invoke(string $brandId, PolicyData $data): Policy
    {
        return DB::transaction(function () use ($brandId, $data): Policy {
            $policy = new Policy([
                'slug' => $data->slug,
                'name' => $data->name,
            ]);

            $policy->forceFill(['brand_id' => $brandId])->save();

            return $policy;
        });
    }
}
