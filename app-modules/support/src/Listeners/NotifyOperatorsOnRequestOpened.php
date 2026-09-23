<?php

declare(strict_types=1);

namespace Konventix\Support\Listeners;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Platform\Enums\Permission as PlatformPermission;
use Konventix\Platform\Permissions\PermissionHolders;
use Konventix\Support\Data\SupportNoticeData;
use Konventix\Support\Enums\Permission;
use Konventix\Support\Enums\SupportCategory;
use Konventix\Support\Events\SupportRequestOpened;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Listeners\Concerns\ResolvesConsoleFallbacks;
use Konventix\Support\Models\SupportRequest;
use Throwable;

/**
 * The operators' half of `SupportRequestOpened` (design §7): everyone who
 * can triage gets the report in the console bell and in their inbox, right
 * away.
 *
 * **In addition to the alert, never instead of it** (D-SUP-10). A
 * "purchase affected" report also raises `KonventixSupportPurchaseAffected`
 * on the counter — that is for whoever is on call at 23:00. This is the
 * in-application process: the bell an operator already watches and a mail
 * that survives them not watching it. Two audiences, two mechanisms, and
 * the report rides Support's **own** topic key, never Commerce's
 * (D-SUP-06).
 *
 * Who is "everyone who can triage" depends on where the report came from:
 *
 * - filed on a brand host → holders of `support.triage-requests` **on that
 *   brand**. A report about sxlan's checkout is not xpertise's business.
 * - filed on the operator console → there is no brand to hold a capability
 *   on, so D-SUP-12 names the console's own gate instead: holders of
 *   `platform.access-admin`. Whoever filed it was standing in the console;
 *   whoever answers it will be too.
 *
 * The console case still needs *a* brand for Messaging, whose delivery log,
 * suppression list and quota are all brand-scoped and whose `brand_id` is
 * not nullable. The operator's oldest brand is used, and that is a
 * deliberate stand-in rather than a meaning: `filed_on_brand_id` on the row
 * stays null, which is what the console and every metric read.
 *
 * Every send is best-effort and independently wrapped: one recipient whose
 * address has hard-bounced must not cost the other four their notice, and
 * no notification failure may ever fail the report that caused it (§7).
 */
final class NotifyOperatorsOnRequestOpened
{
    use ResolvesConsoleFallbacks;

    /** How much of the report the notice quotes (design §7). */
    private const int DETAIL_EXCERPT = 200;

    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly PermissionHolders $permissionHolders,
        private readonly SupportLinks $links,
    ) {}

    public function handle(SupportRequestOpened $event): void
    {
        $request = SupportRequest::query()->whereKey($event->requestId)->first();

        if ($request === null) {
            return;
        }

        // Scoped bindings are resolved here, never in the constructor: the
        // dispatcher builds listeners with the worker's base container and
        // would hand this one the first Octane request's operator forever
        // (`.ai/rules/listeners.md`).
        $operatorId = $this->consoleOperatorId();

        $recipients = $event->brandId !== null
            ? $this->permissionHolders->userIdsWithPermission($event->brandId, Permission::TriageRequests->value)
            : ($operatorId === null ? [] : $this->permissionHolders->userIdsWithPermission(
                $operatorId,
                PlatformPermission::AccessAdmin->value,
            ));

        $messageBrandId = $this->messagingBrandId($event->brandId, $operatorId);

        if ($recipients === [] || $messageBrandId === null) {
            Log::warning('A support request reached nobody: no holder or no brand to send on.', [
                'request_id' => $request->getKey(),
                'brand_id' => $event->brandId,
                'holders' => count($recipients),
            ]);

            return;
        }

        $payload = $this->notice($request, $event);

        foreach ($recipients as $userId) {
            try {
                $this->sendMessage->handle(new SendMessageRequest(
                    recipientId: $userId,
                    templateKey: 'support.request-notice',
                    payload: $payload,
                    brandId: $messageBrandId,
                    topicKey: 'support.requests',
                    // The topic is digestable for later, calmer sends; a
                    // new report is not one of them. Same reasoning as
                    // Commerce's operations notices (D-CNT-08).
                    urgent: true,
                ));
            } catch (Throwable $exception) {
                Log::warning('A support request notice could not be sent.', [
                    'request_id' => $request->getKey(),
                    'user_id' => $userId,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function notice(SupportRequest $request, SupportRequestOpened $event): SupportNoticeData
    {
        $reference = $request->reference();
        $categories = $this->categoryLabels($request);

        $title = __('New support request :reference · :categories', ['reference' => $reference, 'categories' => $categories]);

        $detail = implode(' ', array_filter([
            Str::limit($request->message, self::DETAIL_EXCERPT),
            '—',
            __('Brand: :brand.', ['brand' => $request->context->brand ?? __('unknown')]),
            $request->context->route === null ? null : __('Route: :route.', ['route' => $request->context->route]),
            $event->release === null ? null : __('Release: :release.', ['release' => $event->release]),
        ]));

        return new SupportNoticeData(
            reference: $reference,
            title: $title,
            detail: $detail,
            url: $this->links->consoleUrl($request),
        );
    }

    private function categoryLabels(SupportRequest $request): string
    {
        $labels = array_map(
            fn (SupportCategory $category): string => $category->label(),
            $request->categoryCases(),
        );

        return $labels === [] ? __('No category') : implode(', ', $labels);
    }
}
