<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\Log;
use Konventix\Support\Models\SupportRequest;

/**
 * Retention (design §10.7, D-SUP-11): a closed report and its thread are
 * deleted once the window has passed.
 *
 * **Deleted, not anonymized.** The GDPR handler keeps the row because it is
 * the operator's incident record while the incident is recent; retention is
 * the other end of that argument — after the window there is no incident
 * left to record, and the most private thing to do with what somebody
 * typed is to not have it.
 *
 * The clock is `closed_at` and only `closed_at`. An open report is never
 * purged however old it is: its age is a triage failure, not a retention
 * one, and `support_requests_unseen_oldest_age_seconds` is what says so.
 *
 * Messages go with the request through the table's cascading foreign key,
 * so this is one delete rather than a walk. Chunked because the first run
 * after a long window is the large one, and `withoutOverlapping()` on the
 * schedule keeps a slow run from being joined by the next.
 */
final class PurgeSupportRequests
{
    public const int CHUNK = 500;

    /**
     * @return int the number of requests deleted
     */
    public function handle(?int $days = null): int
    {
        $days = $days ?? (int) config('support.retention.days', 180);

        // A window of zero (or worse, a negative one) would delete every
        // closed report the moment it closed. Config is operator-editable,
        // so it is checked rather than trusted.
        if ($days < 1) {
            Log::warning('support.retention.skipped', ['days' => $days]);

            return 0;
        }

        $cutoff = now()->subDays($days);
        $deleted = 0;

        do {
            $ids = SupportRequest::query()
                ->whereNotNull('closed_at')
                ->where('closed_at', '<', $cutoff)
                ->orderBy('closed_at')
                ->limit(self::CHUNK)
                ->pluck('id')
                ->all();

            if ($ids === []) {
                break;
            }

            $deleted += SupportRequest::query()->whereKey($ids)->delete();
        } while (count($ids) === self::CHUNK);

        // Ids and counts, never a message or an address
        // (`.ai/rules/observability.md`).
        Log::info('support.retention.purged', ['deleted' => $deleted, 'days' => $days]);

        return $deleted;
    }
}
