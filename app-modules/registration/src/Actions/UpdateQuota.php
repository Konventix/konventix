<?php

declare(strict_types=1);

namespace Konventix\Registration\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Konventix\Registration\Data\QuotaData;
use Konventix\Registration\Models\Quota;

/**
 * Renames a quota or moves its capacity.
 *
 * Capacity may not drop below what is already consumed: those registrations
 * exist and were accepted against this quota, and a negative remainder would
 * either have to un-accept someone or quietly become a lie the waitlist then
 * reasons about. Raising capacity is always allowed — and is what an
 * organizer opening more places actually wants, since `QuotaReleased` only
 * fires on release, so the waitlist is drained by
 * `PromoteFromWaitlist` on the next release rather than by this edit.
 */
final class UpdateQuota
{
    public function handle(Quota $quota, QuotaData $data): Quota
    {
        return DB::transaction(function () use ($quota, $data): Quota {
            /** @var Quota $locked */
            $locked = Quota::withoutBrandScope()->lockForUpdate()->findOrFail($quota->getKey());

            if ($data->capacity < $locked->consumed) {
                throw ValidationException::withMessages([
                    'capacity' => __('Capacity cannot go below the :consumed places already taken.', [
                        'consumed' => $locked->consumed,
                    ]),
                ]);
            }

            $locked->forceFill([
                'name' => $data->name,
                'capacity' => $data->capacity,
            ])->save();

            return $locked->refresh();
        });
    }
}
