<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Konventix\Platform\Brands\BrandContext;
use Konventix\Support\Queries\KnownIssues;

/**
 * `GET /support/known-issues` — what the report dialog asks before somebody
 * types a report that has already been filed (design §9.4).
 *
 * **Unauthenticated on purpose, and therefore deliberately thin.** The
 * people this banner is for are the ones who have not signed in and whose
 * checkout just broke. So the answer is a list of `{id, notice}` and
 * nothing else: no message, no category, no state, no timestamps, no count.
 * A stranger learns "we know the payment page is slow"; they learn nothing
 * about who else reported it or when.
 *
 * **Fetched when the dialog opens, never on page load.** A prop on every
 * page of every brand host would be a query on every page of every brand
 * host, to render a banner almost nobody sees; the design says the shared
 * props stay lean and this endpoint carries the cost only for the people
 * who actually open the dialog.
 *
 * The sixty-second cache lives in {@see KnownIssues}, keyed by brand, so
 * the load this endpoint can take is bounded by brands and not by visitors.
 * There is no rate limit on top of it for the same reason: every answer is
 * a cache read, and the one thing behind it is a query returning a handful
 * of rows.
 */
final class KnownIssueController
{
    public function __invoke(KnownIssues $knownIssues): JsonResponse
    {
        $brandId = app(BrandContext::class)->current()?->getKey();

        return response()->json([
            'knownIssues' => $knownIssues->activeFor($brandId === null ? null : (string) $brandId),
        ]);
    }
}
