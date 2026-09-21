<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Support\Models\SupportRequest;

/**
 * The free-text pointer at whatever tracker the operator really works in
 * (design §9.2).
 *
 * The module's SPEC fixes the scope ceiling here and this Action keeps it:
 * `external_reference` is a string, not an integration. No URL rule, no
 * issue-number pattern, no lookup — a deployment that tracks bugs in GitLab
 * writes `#306`, one that tracks them in a spreadsheet writes a row number,
 * and neither has to wait for a release.
 *
 * Blank clears it, so the field behaves the way its input does. No domain
 * event: nothing outside this module could act on it, and §10.4 fixes the
 * public contract at four events. The audit entry is written explicitly by
 * the caller after this returns (Round 6, D-BATCH).
 */
final class SetExternalReference
{
    public function handle(SupportRequest $request, ?string $reference): SupportRequest
    {
        $reference = $reference === null || trim($reference) === '' ? null : trim($reference);

        if ($request->external_reference === $reference) {
            return $request;
        }

        DB::transaction(function () use ($request, $reference): void {
            $request->external_reference = $reference;
            $request->save();
        });

        return $request;
    }
}
