<?php

declare(strict_types=1);

namespace Konventix\Compliance\Actions;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Konventix\Compliance\Enums\DataSubjectRequestState;
use Konventix\Compliance\Events\SubjectRequestRefused;
use Konventix\Compliance\Models\DataSubjectRequest;

/**
 * Refuses a subject request on a stated legal ground.
 *
 * A refusal is a legitimate outcome (Art. 12(5), Art. 17(3) GDPR), and the
 * ground is mandatory: an unexplained refusal is indistinguishable from an
 * ignored request when a supervisory authority asks a year later.
 *
 * A fulfilled request cannot be refused afterwards — the data is already
 * handed over or already gone, so the refusal would describe something that
 * did not happen.
 */
final class RefuseSubjectRequest
{
    public function __invoke(DataSubjectRequest $request, string $reasonCode): DataSubjectRequest
    {
        if (trim($reasonCode) === '') {
            throw new InvalidArgumentException('Refusing a subject request requires a reason code.');
        }

        if ($request->state === DataSubjectRequestState::Fulfilled) {
            throw new InvalidArgumentException('A fulfilled subject request can no longer be refused.');
        }

        return DB::transaction(function () use ($request, $reasonCode): DataSubjectRequest {
            $request->forceFill([
                'state' => DataSubjectRequestState::Refused,
                'reason_code' => $reasonCode,
                'closed_at' => now(),
            ])->save();

            event(new SubjectRequestRefused(
                requestId: $request->getKey(),
                subjectId: $request->subject_id,
                brandId: $request->filed_on_brand_id ?? '',
                reasonCode: $reasonCode,
            ));

            return $request;
        });
    }
}
