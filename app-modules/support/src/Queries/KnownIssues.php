<?php

declare(strict_types=1);

namespace Konventix\Support\Queries;

use Illuminate\Support\Facades\Cache;
use Konventix\Support\Data\KnownIssueData;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Models\SupportRequest;

/**
 * What the report dialog's banner shows before somebody files the same
 * report again (design §9.4).
 *
 * A known issue is an ordinary request that triage marked: the operator
 * picks one report as the canonical instance of a problem and writes a
 * sentence for everybody else. So this is a query over `support_requests`
 * and not a second table — the mark is reversible, the notice is editable,
 * and the row goes on being a report with a thread.
 *
 * Three rules, each of which the endpoint above depends on:
 *
 * - **Not terminal.** Resolving or declining the canonical report takes the
 *   banner down by itself; nobody has to remember to unmark it.
 * - **This brand or none.** `filed_on_brand_id` is provenance
 *   (ADR-0050 §1), so a report filed on the operator console — which has no
 *   brand — is the operator-wide notice: "the payment provider is down"
 *   belongs on every brand host at once.
 * - **Cached for sixty seconds**, per brand. The dialog asks on every open,
 *   from every visitor of every page, and the answer is a handful of rows
 *   that change a few times a day. Sixty seconds is also the longest a
 *   newly marked issue can stay invisible, which is the trade the design
 *   names.
 *
 * Nothing here is authorized, because the endpoint in front of it is not:
 * the whole answer is {@see KnownIssueData}, which carries an id and a
 * sentence an operator wrote.
 */
final class KnownIssues
{
    /** Design §9.4. Also the longest a fresh mark stays invisible. */
    public const int CACHE_SECONDS = 60;

    /**
     * The active notices for one brand host, newest mark first.
     *
     * @return list<KnownIssueData>
     */
    public function activeFor(?string $brandId): array
    {
        return Cache::remember(
            self::cacheKey($brandId),
            self::CACHE_SECONDS,
            fn (): array => $this->fresh($brandId),
        );
    }

    /**
     * The cache key one brand's banner reads. Public so a test — and a
     * later console action that wants the banner to change at once rather
     * than within the minute — can forget it by name instead of clearing
     * the whole store.
     */
    public static function cacheKey(?string $brandId): string
    {
        return 'support:known-issues:'.($brandId ?? 'console');
    }

    /**
     * @return list<KnownIssueData>
     */
    private function fresh(?string $brandId): array
    {
        return array_values(SupportRequest::query()
            ->where('is_known_issue', true)
            ->whereIn('state', array_map(
                fn (SupportRequestState $state): string => $state->value,
                SupportRequestState::open(),
            ))
            ->where(fn ($query) => $query
                ->whereNull('filed_on_brand_id')
                ->when($brandId !== null, fn ($nested) => $nested->orWhere('filed_on_brand_id', $brandId)))
            ->orderByDesc('updated_at')
            ->get()
            ->map(KnownIssueData::ofRequest(...))
            ->all());
    }
}
