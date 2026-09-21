<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Konventix\Support\Events\SupportRequestKnownIssueMarked;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Queries\KnownIssues;

/**
 * Marking one report as the canonical instance of a problem, or unmarking
 * it (design §9.2, §9.4).
 *
 * This is the one write in the module that changes what an **unrelated
 * visitor** sees: from here the report's notice appears in the dialog of
 * everybody about to report the same thing. That is why it carries its own
 * event and its own audit entry rather than being a column change nobody
 * can trace — `SupportRequestKnownIssueMarked` implements `AuditableEvent`,
 * so Compliance's wildcard subscriber records who flipped it (§10.8).
 *
 * Unmarking clears the notice, because a notice nobody can see is a
 * sentence that will be wrong by the time it is shown again.
 *
 * The banner's own cache is forgotten here rather than left to expire. A
 * minute is an acceptable delay for a *new* notice appearing; it is not an
 * acceptable delay for a wrong one coming down, and the two are the same
 * key.
 */
final class MarkKnownIssue
{
    public function handle(
        SupportRequest $request,
        bool $isKnownIssue,
        ?string $notice,
        string $actorUserId,
    ): SupportRequest {
        $notice = $notice === null || trim($notice) === '' ? null : trim($notice);

        DB::transaction(function () use ($request, $isKnownIssue, $notice): void {
            $request->is_known_issue = $isKnownIssue;
            $request->known_issue_notice = $isKnownIssue ? $notice : null;
            $request->save();
        });

        // Both keys: the report was filed on one host, and a brandless one
        // is the operator-wide notice every brand's banner reads.
        Cache::forget(KnownIssues::cacheKey($request->filed_on_brand_id));
        Cache::forget(KnownIssues::cacheKey(null));

        event(new SupportRequestKnownIssueMarked(
            requestId: (string) $request->getKey(),
            brandId: $request->filed_on_brand_id,
            isKnownIssue: $isKnownIssue,
            actorUserId: $actorUserId,
        ));

        return $request;
    }
}
