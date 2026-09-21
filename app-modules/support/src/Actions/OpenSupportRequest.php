<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Konventix\Support\Data\SupportContextData;
use Konventix\Support\Data\SupportRequestInputData;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Enums\SupportRequestState;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Models\SupportRequest;

/**
 * The module's one public write (design §6.4): a stranger on a brand host,
 * an operator in the console, anybody at all files a report.
 *
 * Deliberately **not** an `AuthorizingAction` (ADR-0015). Every other write
 * in this codebase asks a capability first; this one cannot, because a
 * report channel that required a capability would be a report channel the
 * people who find the bugs cannot reach (ADR-0063). What stands in place of
 * authorization is the endpoint's own defence in depth — the two rate
 * limits, the honeypot and the captcha seam of §6.4 — and all of it lives
 * in the controller, so this Action stays the one thing it should be: the
 * write, in a transaction, followed by the event.
 *
 * The event is dispatched **after** the transaction commits, not inside it.
 * Its three listeners send mail, count metrics and — in S2 — feed the
 * console; a rollback after any of that has happened would leave an
 * operator notified about a report that does not exist.
 *
 * `email` is stored only for a guest. An account's address belongs to
 * Identity and is reachable from the account id whenever a mail needs it
 * (Messaging's recipient read model does exactly that), so copying it here
 * would be a second, ageing copy of somebody's personal data in a table
 * that keeps rows for 180 days after closing.
 */
final class OpenSupportRequest
{
    public function handle(
        SupportRequestInputData $input,
        SupportContextData $context,
        ?string $userId,
        ?string $brandId,
    ): SupportRequest {
        $categories = array_map(
            fn (SupportCategory $category): string => $category->value,
            $input->selectedCategories(),
        );

        $request = DB::transaction(fn (): SupportRequest => SupportRequest::query()->create([
            // Provenance, never a scope (ADR-0050 §1): null means the
            // report was filed on the operator console.
            'filed_on_brand_id' => $brandId,
            'user_id' => $userId,
            'email' => $userId === null ? Str::lower(trim((string) $input->email)) : null,
            'contact_consent' => $input->contactConsent,
            'categories' => $categories,
            'message' => $input->message,
            'state' => SupportRequestState::Received,
            'is_known_issue' => false,
            'context' => $context,
        ]));

        // The `support.request` record of design §10.6. It carries ids and
        // nothing else: the message and the address are personal data and
        // `.ai/rules/observability.md` keeps both out of every log sink.
        // Both correlation ids are here on purpose — that is what makes a
        // report findable from the logs *and* the logs findable from the
        // report (ADR-0056).
        Log::info('support.request', [
            'request_id' => $request->getKey(),
            'reference' => $request->reference(),
            'categories' => implode(',', $categories),
            'page_correlation_id' => $context->pageCorrelationId,
            'submit_correlation_id' => $context->submitCorrelationId,
            'release' => $context->release,
            'reported_route' => $context->route,
        ]);

        event(new SupportRequestOpened(
            requestId: $request->getKey(),
            brandId: $brandId,
            userId: $userId,
            categories: $categories,
            release: $context->release,
        ));

        return $request;
    }
}
