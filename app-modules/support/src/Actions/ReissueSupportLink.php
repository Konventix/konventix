<?php

declare(strict_types=1);

namespace Konventix\Support\Actions;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Konventix\Messaging\Actions\SendMessage;
use Konventix\Messaging\Data\SendMessageRequest;
use Konventix\Support\Data\SupportLinkData;
use Konventix\Support\Links\SupportLinks;
use Konventix\Support\Models\SupportRequest;
use Throwable;

/**
 * A guest asks for a new link to their own report (design §8.2).
 *
 * The whole of the security of this endpoint is in what it does **not**
 * take and what it does **not** answer.
 *
 * It takes a reference and nothing else. The fresh link goes to the address
 * stored on the row; a form that accepted an address would let anybody
 * holding a reference — which is printed in a mail, quoted on the phone and
 * eight characters long — move somebody else's report to an inbox of their
 * choosing.
 *
 * It answers `void`, and the controller says the same sentence whichever
 * way this went. An unknown reference, a purged report, a report filed by
 * an *account* (which has a page and needs no link), a report whose address
 * was erased on request — all four send nothing and all four look exactly
 * like a success. An endpoint that distinguished them would be a free
 * oracle for "does this reference exist", and the reference space is small
 * enough that the answer would be worth having.
 *
 * (The design's §8.2 sketched a 404 for a purged request. It is
 * deliberately not built that way: the same page for every input is the
 * stronger property, and a person whose report was purged is told the same
 * thing as a person who typed the reference wrong — check your mail, and
 * file a new report if nothing arrives.)
 *
 * The rate limit that stops this being a mail cannon at one known address
 * is the route's named `support-link` limiter, three an hour per reference.
 */
final class ReissueSupportLink
{
    public function __construct(
        private readonly SendMessage $sendMessage,
        private readonly SupportLinks $links,
    ) {}

    public function handle(string $reference): void
    {
        $request = $this->find($reference);

        if ($request === null) {
            return;
        }

        // An account follows their report under "My support requests"; the
        // signed link exists only for somebody who has no session to
        // authorize them. Minting one for an account would be a second,
        // weaker key to a page the password already opens.
        if ($request->user_id !== null || $request->email === null) {
            return;
        }

        // Every guest report is filed on a brand host — the console is
        // authenticated, so a console-filed report always has an account.
        // Messaging is brand-scoped down to its quota and its suppression
        // list, so a brandless one here would be a send with nowhere to be
        // booked.
        $brandId = $request->filed_on_brand_id;

        if ($brandId === null) {
            Log::warning('A support link re-issue had no brand to be sent on.', [
                'request_id' => $request->getKey(),
            ]);

            return;
        }

        try {
            $this->sendMessage->handle(new SendMessageRequest(
                recipientId: null,
                templateKey: 'support.request-link',
                payload: new SupportLinkData(
                    reference: $request->reference(),
                    requestUrl: $this->links->reporterUrl($request),
                ),
                // The stored address, never one that arrived with the form.
                recipientEmail: $request->email,
                brandId: $brandId,
                // No topic: there is no account to hold a preference, and
                // this mail *is* the access — the same reasoning the guest
                // receipt states.
                topicKey: null,
            ));
        } catch (Throwable $exception) {
            Log::warning('A support link could not be re-issued.', [
                'request_id' => $request->getKey(),
                'reason' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The reference is the last eight characters of the ULID, upper-cased
     * for print. Nobody retypes it the way it was printed, so case is
     * normalised — and everything outside the alphabet is dropped rather
     * than escaped, because a `%` reaching a `like` pattern would turn a
     * lookup into a scan that matches somebody else's report.
     */
    private function find(string $reference): ?SupportRequest
    {
        $needle = Str::lower(preg_replace('/[^0-9A-Za-z]/', '', $reference) ?? '');

        if ($needle === '') {
            return null;
        }

        return SupportRequest::query()->where('id', 'like', '%'.$needle)->first();
    }
}
