<?php

declare(strict_types=1);

namespace Konventix\Support\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Konventix\Support\Actions\PostReporterReply;
use Konventix\Support\Actions\ReissueSupportLink;
use Konventix\Support\Data\SupportLinkReissueInputData;
use Konventix\Support\Data\SupportReplyInputData;
use Konventix\Support\Data\SupportReporterRequestData;
use Konventix\Support\Data\SupportRequestMessageData;
use Konventix\Support\Exceptions\SupportRequestClosed;
use Konventix\Support\Http\Middleware\RenderExpiredSupportLink;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Models\SupportRequest;
use Konventix\Support\Models\SupportRequestMessage;

/**
 * A guest's own report, reached by the signed link in their receipt
 * (design §8.2).
 *
 * **The signature is the authorization, and it is the whole of it.** There
 * is no session here and none is written: somebody who reported that the
 * shop was broken without ever making an account must be able to read the
 * answer, and the only thing that proves they are the person who filed it
 * is that they are holding the URL that was mailed to the address on the
 * row. `signed` middleware does the proving; this controller never
 * revalidates it and never second-guesses it.
 *
 * The page it renders is the same component the account page renders, with
 * one prop different: `replyUrl`, which here is signed and expires with the
 * link it was minted from. An account has a session to authorize the POST;
 * a guest has the same key for reading and for writing, which is what §8.2
 * means by "the form re-posts to the URL it came from".
 *
 * Nothing here extends a link. The reply URL and the redirect after a reply
 * both carry the expiry this visit arrived with, so a page kept open for a
 * month does not quietly become a page open forever —
 * {@see SupportLinks::guestShowUrl()} states that once.
 *
 * An expired or tampered signature never reaches any of this: it is caught
 * one frame out by
 * {@see RenderExpiredSupportLink}, which
 * renders the re-issue page instead of a 403.
 */
final class GuestSupportRequestController
{
    public function __construct(private readonly SupportLinks $links) {}

    public function show(string $supportRequest, Request $request): Response
    {
        $model = $this->findGuestRequest($supportRequest);
        $expiresAt = $this->linkExpiry($request);

        return Inertia::render('support/requests/Show', [
            'request' => SupportReporterRequestData::ofRequest(
                $model,
                $this->reporterMessages($model),
            ),
            'replyUrl' => $this->links->guestReplyUrl($model, $expiresAt),
            'isGuestLink' => true,
        ]);
    }

    public function reply(
        string $supportRequest,
        SupportReplyInputData $data,
        Request $request,
        PostReporterReply $postReporterReply,
    ): RedirectResponse {
        $model = $this->findGuestRequest($supportRequest);

        try {
            // No author id: that null is already what this table means by
            // "the reporter" (§4.2), so nothing downstream learns a case.
            $postReporterReply->handle($model, $data->body, null);
        } catch (SupportRequestClosed) {
            throw ValidationException::withMessages([
                'body' => 'This report has been closed, so it takes no further replies. File a new one if it is still happening.',
            ]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Your reply was added.']);

        // Back to the signed page explicitly rather than through `back()`:
        // a redirect that depended on a `Referer` header would land a guest
        // on the brand's front page whenever their browser withheld one,
        // and there is no session here to find the way back with.
        return redirect()->to($this->links->guestShowUrl($model, $this->linkExpiry($request)));
    }

    /**
     * The self-service re-issue (design §8.2), and the only endpoint of
     * this module that is deliberately uninformative.
     *
     * It answers the same sentence for a reference that exists, one that
     * never did, one that was purged and one that belongs to an account —
     * see {@see ReissueSupportLink}, which is where the reasoning lives.
     * The route carries `throttle:support-link`, three an hour per
     * reference, so this cannot be turned into a mail cannon aimed at one
     * known inbox.
     */
    public function reissue(
        SupportLinkReissueInputData $data,
        ReissueSupportLink $reissueSupportLink,
    ): JsonResponse {
        $reissueSupportLink->handle($data->reference);

        // JSON and not a redirect, because there is nowhere to redirect to:
        // the page that asked was rendered *from* a link that no longer
        // verifies, so `back()` would come straight back through the same
        // dead URL and re-render the page from scratch, losing the sentence
        // that says what happened. The page posts through `useHttp` and
        // announces the answer where the person is standing — the same
        // shape, and the same reason, as the report dialog's own submit.
        //
        // 202: we have taken the request. Not 200 with a result, because
        // the one thing this endpoint must never report is whether
        // anything was sent.
        return response()->json(['ok' => true], 202);
    }

    /**
     * The expiry this visit's signature carries.
     *
     * `temporarySignedRoute` always writes it, and `signed` has already
     * verified that it is the one that was signed — so it cannot be moved
     * by whoever is holding the URL, which is what makes it safe to mint
     * the next URL from.
     */
    private function linkExpiry(Request $request): CarbonImmutable
    {
        $expires = (int) $request->query('expires', '0');

        return CarbonImmutable::createFromTimestamp($expires);
    }

    private function findGuestRequest(string $supportRequest): SupportRequest
    {
        /** @var SupportRequest|null $model */
        $model = SupportRequest::query()->whereKey($supportRequest)->first();

        // Purged by the retention sweep, or an id that never existed. A
        // valid signature over a row that is gone is still nothing to show.
        abort_if($model === null, 404);

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
}
