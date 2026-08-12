<?php

declare(strict_types=1);

namespace Konventix\Admission\Actions;

use Carbon\CarbonInterface;
use Konventix\Admission\Enums\AdmissionState;
use Konventix\Admission\Models\Admission;

/**
 * The single authority answering "is this admission valid right now?"
 * (ADR-0005, SPEC.md "Owns" — "validity evaluation"). Check-in asks this,
 * never "is it paid?" — payment state belongs to Commerce, confirmation
 * state belongs to Registration, and the door has no business reading
 * either. This class reads only `Admission::$state` and its validity
 * window; it contains no reference to payment, invoices, or orders at all —
 * see `tests/EvaluateAdmissionValidityTest.php`, which asserts as much by
 * scanning this file's source.
 */
final class EvaluateAdmissionValidity
{
    public function handle(Admission $admission, ?CarbonInterface $now = null): bool
    {
        $now ??= now();

        if (! in_array($admission->state, [AdmissionState::Issued, AdmissionState::Assigned], true)) {
            return false;
        }

        if ($admission->valid_from !== null && $now->lt($admission->valid_from)) {
            return false;
        }

        if ($admission->valid_until !== null && $now->gt($admission->valid_until)) {
            return false;
        }

        return true;
    }
}
