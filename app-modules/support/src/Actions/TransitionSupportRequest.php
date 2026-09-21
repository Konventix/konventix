<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\DB;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Events\SupportRequestStateChanged;
use Konventix\Support\Exceptions\InvalidSupportTransition;
use Konventix\Support\Models\SupportRequest;

/**
 * The one way a report's state ever changes (design §4.3, §9.2). Business
 * logic in an Action with the transaction inside it; the console controller
 * of S2 translates HTTP into a call here and nothing more.
 *
 * The transition table lives on {@see SupportRequestState}; this enforces
 * it and owns the three timestamps that hang off it, which is the part that
 * is easy to get subtly wrong:
 *
 * - `seen_at` is stamped once, on the first move out of `received`, and
 *   never re-stamped. It is the end of the clock
 *   `support_requests_unseen_oldest_age_seconds` runs, and a reopen must
 *   not restart it — the report *was* seen.
 * - `resolved_at` marks the one state that claims a fix, so `wont_fix`
 *   leaves it null.
 * - `closed_at` is set by both terminal states and is the **only** input to
 *   the retention clock (D-SUP-11). Reopening clears it, so a reopened
 *   report is never purged out from under the person still waiting on it.
 *
 * A move to the state a request is already in is a no-op rather than an
 * error: the console's first-open-sets-seen path runs on every page load,
 * and the second one must not throw or emit a second event.
 */
final class TransitionSupportRequest
{
    public function handle(
        SupportRequest $request,
        SupportRequestState $to,
        ?string $actorUserId = null,
    ): SupportRequest {
        $from = $request->state;

        if ($from === $to) {
            return $request;
        }

        if (! $from->canMoveTo($to)) {
            throw InvalidSupportTransition::between($from, $to);
        }

        DB::transaction(function () use ($request, $to): void {
            $request->state = $to;

            // Stamped once. A reopen does not un-see a report.
            if ($request->seen_at === null && $to !== SupportRequestState::Received) {
                $request->seen_at = now();
            }

            $request->resolved_at = $to === SupportRequestState::Resolved ? now() : null;

            // The retention clock, and nothing else, reads this (D-SUP-11).
            $request->closed_at = $to->isTerminal() ? now() : null;

            $request->save();
        });

        event(new SupportRequestStateChanged(
            requestId: $request->getKey(),
            brandId: $request->filed_on_brand_id,
            from: $from->value,
            to: $to->value,
            actorUserId: $actorUserId,
        ));

        return $request;
    }
}
