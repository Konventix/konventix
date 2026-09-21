<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Support\Models\SupportRequest;

/**
 * Who owns a report (design §9.2).
 *
 * Deliberately the smallest Action in the module, and deliberately still an
 * Action: the write is a transaction and the controller translates HTTP,
 * nothing more (CLAUDE_extra). Assignment changes no state, sends no mail
 * and emits **no domain event** — there is nothing another module could
 * usefully do with "Anna took this one", and §10.4 fixes Support's public
 * contract at four events. The audit trail still gets an entry, through
 * `AuditRecorder` at the call site: that is the explicit half of the hybrid
 * trail (Round 6, D-BATCH), recorded after this Action returns so an audit
 * write can never take part in the caller's rollback.
 *
 * Null is a first-class argument, not an absent one: handing a report back
 * to the pile is the same act as taking it.
 *
 * The assignee is a plain ULID with no foreign key (ADR-0014): the account
 * lives in Identity and this module may not read it. Whether the id belongs
 * to somebody who may triage is answered by `PermissionHolders` before we
 * get here — and a person later stripped of the capability keeps the row
 * they are named on, because the row is a record of what happened.
 */
final class AssignSupportRequest
{
    public function handle(SupportRequest $request, ?string $assigneeUserId): SupportRequest
    {
        if ($request->assigned_to_user_id === $assigneeUserId) {
            return $request;
        }

        DB::transaction(function () use ($request, $assigneeUserId): void {
            $request->assigned_to_user_id = $assigneeUserId;
            $request->save();
        });

        return $request;
    }
}
