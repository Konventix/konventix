<?php

declare(strict_types=1);

namespace Konventix\Admission\Http\Concerns;

use Illuminate\Http\Request;
use Konventix\Admission\Models\Admission;

/**
 * The ownership check every manager route shares (#292 §4.3): the
 * signed-in account bought the registration this admission came from.
 * Decided entirely on `admissions.buyer_user_id` — never a Registration
 * table (ADR-0004) — and answered with the 403 `TicketDownloadController`
 * gives a stranger, so the two surfaces never disagree about the same
 * person.
 */
trait FindsManagedAdmission
{
    private function managedAdmission(Request $request, Admission $admission): Admission
    {
        abort_unless($admission->buyer_user_id === (string) $request->user()->getAuthIdentifier(), 403);

        return $admission;
    }
}
