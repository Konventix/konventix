<?php

declare(strict_types=1);

namespace Konventix\Messaging\Inbox;

use Illuminate\Database\Eloquent\Builder;
use Konventix\Messaging\Enums\TopicAudience;
use Konventix\Messaging\Models\InboxNotification;
use Konventix\Messaging\Topics\TopicRegistry;
use Konventix\Platform\Models\Brand;
use Konventix\Platform\Operators\OperatorContext;

/**
 * The inbox rows one recipient may see on the host they are on (bugfix
 * round 2026-09-21, Task 9). The bell and the inbox page exist on both the
 * brand host and the operator console, and each host serves one audience:
 *
 * - a **brand host** resolves a brand, `BrandScope` narrows the rows to it,
 *   and only participant-audience topics are shown — a `commerce.operations`
 *   notice addressed to an organizer who is also a registrant stays out of
 *   their participant inbox;
 * - the **operator admin host** resolves no brand (ADR-0029) and
 *   `BrandScope` hardens every brand-owned query to nothing, so the rows
 *   are read through `withoutBrandScope()` and narrowed by hand to the
 *   operator-audience topics, to the signed-in recipient and to the brands
 *   this operator owns — never another operator's, however the recipient
 *   is addressed there.
 *
 * A row with no topic (a send without one) is a participant row: it was
 * never declared for the console. The contexts are resolved at call time,
 * never in the constructor — under Octane the request's scoped instances
 * are the ones the middleware filled (`.ai/rules/providers.md`).
 */
final class InboxScope
{
    public function __construct(private readonly TopicRegistry $topics) {}

    /**
     * @return Builder<InboxNotification>
     */
    public function for(string $recipientId): Builder
    {
        $operatorKeys = $this->topics->keysFor(TopicAudience::Operator);
        $operator = app(OperatorContext::class);

        if ($operator->hasOperator()) {
            return InboxNotification::withoutBrandScope()
                ->where('recipient_id', $recipientId)
                ->whereIn('topic_key', $operatorKeys)
                ->whereIn('brand_id', Brand::query()
                    ->select('id')
                    ->where('operator_id', $operator->id()));
        }

        return InboxNotification::query()
            ->where('recipient_id', $recipientId)
            ->where(fn (Builder $query): Builder => $query
                ->whereNull('topic_key')
                ->orWhereNotIn('topic_key', $operatorKeys));
    }
}
