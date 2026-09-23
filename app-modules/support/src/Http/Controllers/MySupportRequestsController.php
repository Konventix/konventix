<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Support\Actions\PostReporterReply;
use Konventix\Support\Data\SupportMyRequestRowData;
use Konventix\Support\Data\SupportReplyInputData;
use Konventix\Support\Data\SupportReporterRequestData;
use Konventix\Support\Data\SupportRequestMessageData;
use Konventix\Support\Exceptions\SupportRequestClosed;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * "My support requests" — the signed-in reporter's own list and thread
 * (design §8.1).
 *
 * **Authorization is ownership and nothing else.** `user_id === the signed
 * in account`, checked in the query, and everything else is a 404 rather
 * than a 403: a 403 on an id confirms that the id names something, and the
 * ids here are eight characters long in print. There is no capability for
 * this and none is missing — reading your own report is not something
 * anybody can be granted, which is the same reasoning `/my/orders` and
 * `/my/tickets` already carry.
 *
 * **No brand filter, and that is deliberate.** Every other `/my/*` list in
 * this codebase is brand-scoped, because an order placed with one brand is
 * not the other brand's business. A support report is not a purchase: the
 * person filed it about the platform from wherever they happened to be
 * standing, they may have filed it on a brand host they will never visit
 * again, and `SupportRequest` carries no `BelongsToBrand` in the first
 * place (S0). A list that hid their own report because they opened the
 * account menu on the wrong host would be a list that loses reports.
 *
 * The reporter sees **external** entries only. That filter lives in
 * `SupportRequestMessage::scopeVisibleToReporter()` rather than in this
 * controller, so that it is the same filter the guest page applies and
 * there is exactly one place for it to be wrong (§4.2).
 */
final class MySupportRequestsController
{
    public function index(Request $request): Response
    {
        $requests = SupportRequest::query()
            ->where('user_id', $this->actorId($request))
            ->latest('created_at')
            ->get();

        $lastExternal = $this->lastExternalMessageAt($requests->modelKeys());

        return Inertia::render('support/requests/Index', [
            'requests' => $requests
                ->map(fn (SupportRequest $row): SupportMyRequestRowData => SupportMyRequestRowData::ofRequest(
                    $row,
                    $lastExternal->get((string) $row->getKey()),
                ))
                ->values()
                ->all(),
        ]);
    }

    public function show(string $supportRequest, Request $request): Response
    {
        $model = $this->findOwn($supportRequest, $request);

        return Inertia::render('support/requests/Show', [
            'request' => SupportReporterRequestData::ofRequest(
                $model,
                $this->reporterMessages($model),
            ),
            // Built here rather than by Wayfinder on the page, because the
            // guest's twin of this page needs a *signed* URL and the two
            // share one component. One prop, one place it comes from.
            'replyUrl' => route(
                'support.requests.mine.reply',
                ['supportRequest' => (string) $model->getKey()],
                absolute: false,
            ),
            'isGuestLink' => false,
        ]);
    }

    public function reply(
        string $supportRequest,
        SupportReplyInputData $data,
        Request $request,
        PostReporterReply $postReporterReply,
    ): RedirectResponse {
        $model = $this->findOwn($supportRequest, $request);

        try {
            $postReporterReply->handle($model, $data->body, $this->actorId($request));
        } catch (SupportRequestClosed) {
            // A 422 on `body`, where what they typed is still on screen —
            // never a redirect that drops it (see the exception's docblock).
            throw ValidationException::withMessages([
                'body' => __('This report has been closed, so it takes no further replies. File a new one if it is still happening.'),
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Your reply was added.')]);

        return back();
    }

    /**
     * The one ownership check, used by all three endpoints.
     *
     * A report whose `user_id` was nulled by the GDPR handler (§10.5)
     * belongs to nobody and therefore refuses everybody, which is the same
     * rule `MyOrdersController` applies to an order with no payer.
     */
    private function findOwn(string $supportRequest, Request $request): SupportRequest
    {
        /** @var SupportRequest|null $model */
        $model = SupportRequest::query()->whereKey($supportRequest)->first();

        abort_unless(
            $model !== null
                && $model->user_id !== null
                && $model->user_id === $this->actorId($request),
            404,
        );

        return $model;
    }

    /**
     * @return list<SupportRequestMessageData>
     */
    private function reporterMessages(SupportRequest $request): array
    {
        return array_values(SupportRequestMessage::query()
            ->where('support_request_id', $request->getKey())
            ->visibleToReporter()
            ->orderBy('created_at')
            ->get()
            ->map(SupportRequestMessageData::fromModel(...))
            ->all());
    }

    /**
     * When something the reporter can actually see last happened, per
     * request — so the list's "last update" never moves for an internal
     * note (see {@see SupportMyRequestRowData}).
     *
     * @param  array<int, mixed>  $requestIds
     * @return Collection<string, CarbonImmutable>
     */
    private function lastExternalMessageAt(array $requestIds): Collection
    {
        if ($requestIds === []) {
            return collect();
        }

        return SupportRequestMessage::query()
            ->whereIn('support_request_id', $requestIds)
            ->visibleToReporter()
            ->orderByDesc('created_at')
            ->get(['support_request_id', 'created_at'])
            ->groupBy('support_request_id')
            ->map(fn (Collection $group) => $group->first()?->created_at);
    }

    private function actorId(Request $request): string
    {
        return (string) $request->user()?->getAuthIdentifier();
    }
}
